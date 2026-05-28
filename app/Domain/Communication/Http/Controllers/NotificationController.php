<?php

namespace App\Domain\Communication\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Domain\Auth\Models\User;
use App\Http\Controllers\Controller;

class NotificationController extends Controller
{
    /**
     * Get user's notifications.
     */
    public function index(Request $request): JsonResponse
    {
        $userId = $request->query('user_id');
        if (!$userId) {
            return response()->json(['message' => 'User ID is required'], 400);
        }

        $user = User::findOrFail($userId);
        
        // Return unread notifications first, then read ones
        $notifications = $user->notifications()
            ->latest()
            ->paginate($request->query('per_page', 20));

        return response()->json($notifications);
    }

    /**
     * Mark a specific notification as read.
     */
    public function markAsRead(Request $request, string $id): JsonResponse
    {
        $userId = $request->input('user_id');
        if (!$userId) {
            return response()->json(['message' => 'User ID is required'], 400);
        }

        $user = User::findOrFail($userId);
        $notification = $user->notifications()->findOrFail($id);
        $notification->markAsRead();

        return response()->json(['message' => 'Notification marked as read']);
    }

    /**
     * Mark all notifications as read for the user.
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        $userId = $request->input('user_id');
        if (!$userId) {
            return response()->json(['message' => 'User ID is required'], 400);
        }

        $user = User::findOrFail($userId);
        $user->unreadNotifications->markAsRead();

        return response()->json(['message' => 'All notifications marked as read']);
    }
}
