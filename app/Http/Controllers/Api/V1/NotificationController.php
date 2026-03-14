<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * User notification management.
 *
 * All endpoints require auth:sanctum.
 * Uses Laravel's built-in DatabaseNotification system.
 */
class NotificationController extends Controller
{
    /**
     * List user's notifications (paginated).
     *
     * GET /v1/user/notifications
     *
     * Query params:
     *   - per_page (int, 1–50, default 20)
     *   - unread_only (bool, default false)
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min(50, max(1, (int) $request->query('per_page', '20')));
        $unreadOnly = filter_var($request->query('unread_only', 'false'), FILTER_VALIDATE_BOOLEAN);

        $query = $request->user()->notifications();

        if ($unreadOnly) {
            $query->whereNull('read_at');
        }

        $notifications = $query
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return response()->json([
            'data' => NotificationResource::collection($notifications),
            'meta' => [
                'current_page' => $notifications->currentPage(),
                'last_page'    => $notifications->lastPage(),
                'per_page'     => $notifications->perPage(),
                'total'        => $notifications->total(),
                'unread_count' => $request->user()->unreadNotifications()->count(),
            ],
        ]);
    }

    /**
     * Get unread notification count (lightweight for badges).
     *
     * GET /v1/user/notifications/unread-count
     */
    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'count' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    /**
     * Mark a single notification as read.
     *
     * PATCH /v1/user/notifications/{id}/read
     */
    public function markAsRead(Request $request, string $id): JsonResponse
    {
        $notification = $request->user()
            ->notifications()
            ->where('id', $id)
            ->first();

        if (! $notification) {
            return response()->json([
                'message' => 'Không tìm thấy thông báo.',
            ], 404);
        }

        $notification->markAsRead();

        return response()->json([
            'message' => 'Đã đánh dấu đã đọc.',
            'data'    => new NotificationResource($notification->fresh()),
        ]);
    }

    /**
     * Mark all notifications as read.
     *
     * POST /v1/user/notifications/read-all
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        $updated = $request->user()
            ->unreadNotifications()
            ->update(['read_at' => now()]);

        return response()->json([
            'message' => "Đã đánh dấu {$updated} thông báo đã đọc.",
            'updated' => $updated,
        ]);
    }

    /**
     * Delete a single notification.
     *
     * DELETE /v1/user/notifications/{id}
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $deleted = $request->user()
            ->notifications()
            ->where('id', $id)
            ->delete();

        if (! $deleted) {
            return response()->json([
                'message' => 'Không tìm thấy thông báo.',
            ], 404);
        }

        return response()->json([
            'message' => 'Đã xóa thông báo.',
        ]);
    }
}
