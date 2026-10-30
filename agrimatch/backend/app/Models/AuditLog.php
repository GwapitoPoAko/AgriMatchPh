<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only audit trail row. Writes go exclusively through
 * App\Services\AuditLogger (which computes the hash chain). Updates and
 * deletes are blocked here *and* by database triggers.
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    /** Nothing is mass-assignable: AuditLogger uses forceFill() with computed values. */
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit log entries are immutable.'));
        static::deleting(fn () => throw new LogicException('Audit log entries cannot be deleted.'));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Decoded payloads for API output (raw strings stay untouched for hash verification). */
    public function decodedNewValues(): ?array
    {
        return $this->new_values !== null ? json_decode($this->getRawOriginal('new_values'), true) : null;
    }

    public function decodedOldValues(): ?array
    {
        return $this->old_values !== null ? json_decode($this->getRawOriginal('old_values'), true) : null;
    }
}
