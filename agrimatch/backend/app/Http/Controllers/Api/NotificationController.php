<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Web bell drawer: list, unread count, mark read. Strictly scoped to the signed-in user. */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'unread_only' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $user = $request->user();

        $page = AppNotification::query()
            ->where('user_id', $user->id)
            ->when($validated['unread_only'] ?? false, fn ($q) => $q->unread())
            ->with('smsLogs:id,notification_id,status,sent_at')
            ->latest()
            ->latest('id')
            ->paginate($validated['per_page'] ?? 15);

        $page->getCollection()->transform(fn (AppNotification $n) => [
            'id' => $n->id,
            'type' => $n->type,
            'title' => $n->title,
            'body' => $n->body,
            'action_url' => $n->action_url,
            'priority' => $n->priority,
            'channels' => $n->channelList(),
            'sms_status' => $n->smsLogs->first()?->status,
            'read_at' => $n->read_at?->toIso8601String(),
            'created_at' => $n->created_at?->toIso8601String(),
        ]);

        return response()->json($page->toArray() + ['unread_count' => AppNotification::where('user_id', $user->id)->unread()->count()]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json(['unread_count' => AppNotification::where('user_id', $request->user()->id)->unread()->count()]);
    }

    public function markRead(Request $request, int $notification): JsonResponse
    {
        // Scoped lookup: another user's id simply 404s (no existence leak).
        $n = AppNotification::where('user_id', $request->user()->id)->findOrFail($notification);
        if (! $n->read_at) {
            $n->forceFill(['read_at' => now()])->save();
        }

        return response()->json(['data' => ['id' => $n->id, 'read_at' => $n->read_at->toIso8601String()]]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $count = AppNotification::where('user_id', $request->user()->id)->unread()->update(['read_at' => now()]);

        return response()->json(['marked' => $count]);
    }
}
