<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Read-only access to the audit trail for platform admins. */
class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('view-audit-trail');
        $validated = $request->validate([
            'event' => ['nullable', 'string', 'max:60'],
            'user_id' => ['nullable', 'integer'],
            'auditable_type' => ['nullable', 'string', 'max:120'],
            'auditable_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = AuditLog::query()
            ->with('user:id,name,role')
            ->when($validated['event'] ?? null, fn ($q, $e) => $q->where('event', 'like', str_replace(['%', '_'], ['\%', '\_'], $e) . '%'))
            ->when($validated['user_id'] ?? null, fn ($q, $u) => $q->where('user_id', $u))
            ->when($validated['auditable_type'] ?? null, fn ($q, $t) => $q->where('auditable_type', $t))
            ->when($validated['auditable_id'] ?? null, fn ($q, $id) => $q->where('auditable_id', $id))
            ->orderByDesc('id')
            ->paginate($validated['per_page'] ?? 25);

        $page->getCollection()->transform(fn (AuditLog $a) => [
            'id' => $a->id,
            'event' => $a->event,
            'user' => $a->user?->only(['id', 'name', 'role']),
            'subject' => $a->auditable_type ? class_basename($a->auditable_type) . " #{$a->auditable_id}" : null,
            'old_values' => $a->decodedOldValues(),
            'new_values' => $a->decodedNewValues(),
            'ip_address' => $a->ip_address,
            'request_id' => $a->request_id,
            'hash' => $a->hash,
            'created_at' => $a->created_at?->toIso8601String(),
        ]);

        return response()->json($page);
    }

    public function verify(AuditLogger $logger): JsonResponse
    {
        Gate::authorize('view-audit-trail');
        $broken = $logger->verifyChain();

        return response()->json([
            'intact' => $broken === null,
            'entries' => AuditLog::count(),
            'first_broken' => $broken,
        ]);
    }
}
