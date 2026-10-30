<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Writes the immutable, tamper-evident audit trail.
 *
 * Every row stores the SHA-256 of the previous row's hash plus its own
 * canonical content, forming a hash chain:
 *
 *   hash = sha256(prev_hash|event|auditable_type|auditable_id|user_id|old_json|new_json|created_at)
 *
 * Editing or deleting any historical row (even directly in phpMyAdmin with the
 * triggers dropped) breaks every subsequent hash, which `verifyChain()` and the
 * `agrimatch:audit-verify` command detect.
 */
class AuditLogger
{
    public const GENESIS_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    /** Attributes never written to the audit payload. */
    private const REDACTED = ['password', 'remember_token', 'token', 'api_key'];

    public function __construct(private readonly ?Request $request = null)
    {
    }

    /**
     * @param  array<string, mixed>  $new
     * @param  array<string, mixed>  $old
     */
    public function record(string $event, ?Model $subject = null, array $new = [], array $old = [], ?User $actor = null): AuditLog
    {
        $actor ??= $this->request?->user();
        $newJson = $new ? self::canonicalJson($this->redact($new)) : null;
        $oldJson = $old ? self::canonicalJson($this->redact($old)) : null;

        // Serialise writers so two concurrent requests can't both chain off the same parent.
        return Cache::lock('audit-log-chain', 10)->block(5, function () use ($event, $subject, $newJson, $oldJson, $actor) {
            return DB::transaction(function () use ($event, $subject, $newJson, $oldJson, $actor) {
                $prev = AuditLog::query()->orderByDesc('id')->lockForUpdate()->value('hash') ?? self::GENESIS_HASH;
                $createdAt = Carbon::now()->format('Y-m-d H:i:s');

                $row = new AuditLog();
                $row->forceFill([
                    'user_id' => $actor?->getKey(),
                    'event' => $event,
                    'auditable_type' => $subject ? $subject::class : null,
                    'auditable_id' => $subject?->getKey(),
                    'old_values' => $oldJson,
                    'new_values' => $newJson,
                    'ip_address' => $this->request?->ip(),
                    'user_agent' => mb_substr((string) $this->request?->userAgent(), 0, 255) ?: null,
                    'request_id' => $this->request?->attributes->get('request_id'),
                    'prev_hash' => $prev,
                    'hash' => self::hashFor($prev, $event, $subject ? $subject::class : null, $subject?->getKey(), $actor?->getKey(), $oldJson, $newJson, $createdAt),
                    'created_at' => $createdAt,
                ]);
                $row->save();

                return $row;
            });
        });
    }

    /**
     * Only the attributes that actually changed, as [new, old] — for update events.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public static function diff(Model $model): array
    {
        $changes = collect($model->getChanges())->except(['updated_at'])->all();
        $original = collect($model->getOriginal())->only(array_keys($changes))->all();

        return [$changes, $original];
    }

    /**
     * Walks the chain and returns the first broken link, or null if intact.
     *
     * @return array{id: int, reason: string}|null
     */
    public function verifyChain(?int $fromId = null): ?array
    {
        $prev = $fromId
            ? AuditLog::where('id', '<', $fromId)->orderByDesc('id')->value('hash') ?? self::GENESIS_HASH
            : self::GENESIS_HASH;

        $broken = null;

        AuditLog::query()
            ->when($fromId, fn ($q) => $q->where('id', '>=', $fromId))
            ->orderBy('id')
            ->chunkById(500, function ($rows) use (&$prev, &$broken) {
                foreach ($rows as $row) {
                    $raw = $row->getAttributes();
                    if ($raw['prev_hash'] !== $prev) {
                        $broken = ['id' => $row->id, 'reason' => 'prev_hash does not match the previous entry (row removed or reordered).'];

                        return false;
                    }
                    $expected = self::hashFor($prev, $raw['event'], $raw['auditable_type'], $raw['auditable_id'], $raw['user_id'],
                        $raw['old_values'], $raw['new_values'], Carbon::parse($raw['created_at'])->format('Y-m-d H:i:s'));
                    if (! hash_equals($expected, $raw['hash'])) {
                        $broken = ['id' => $row->id, 'reason' => 'Content hash mismatch (row was modified).'];

                        return false;
                    }
                    $prev = $raw['hash'];
                }

                return true;
            });

        return $broken;
    }

    /* ------------------------------------------------------------------ */

    public static function hashFor(string $prev, string $event, ?string $type, int|string|null $id, int|string|null $userId,
        ?string $oldJson, ?string $newJson, string $createdAt): string
    {
        return hash('sha256', implode('|', [
            $prev, $event, $type ?? '', (string) ($id ?? ''), (string) ($userId ?? ''), $oldJson ?? '', $newJson ?? '', $createdAt,
        ]));
    }

    /** Deterministic JSON: recursively key-sorted, unescaped unicode/slashes, floats keep ".0". */
    public static function canonicalJson(array $data): string
    {
        $sort = function ($value) use (&$sort) {
            if (! is_array($value)) {
                if ($value instanceof \DateTimeInterface) {
                    return $value->format('Y-m-d H:i:s');
                }
                if ($value instanceof \BackedEnum) {
                    return $value->value;
                }

                return $value;
            }
            if (array_is_list($value)) {
                return array_map($sort, $value);
            }
            ksort($value);

            return array_map($sort, $value);
        };

        return json_encode($sort($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    private function redact(array $values): array
    {
        foreach (self::REDACTED as $key) {
            if (array_key_exists($key, $values)) {
                $values[$key] = '[redacted]';
            }
        }

        return $values;
    }
}
