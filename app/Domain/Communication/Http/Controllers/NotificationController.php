<?php

namespace App\Domain\Communication\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Domain\Auth\Models\User;
use App\Http\Controllers\Controller;

class NotificationController extends Controller
{
    /**
     * Get user's notifications (including company notifications).
     */
    public function index(Request $request): JsonResponse
    {
        $userId = $request->query('user_id');
        if (!$userId) {
            return response()->json(['message' => 'User ID is required'], 400);
        }

        $user = User::findOrFail($userId);
        
        // Get user's personal notifications
        $userNotifications = $user->notifications()->latest()->get();
        
        // Get active company ID from local storage (passed as query param)
        $companyId = $request->query('company_id');
        
        // Merge with company notifications if company_id is provided
        $allNotifications = $userNotifications;
        
        if ($companyId) {
            $company = \App\Domain\Company\Models\Company::find($companyId);
            if ($company) {
                $companyNotifications = $company->notifications()->latest()->get();
                $allNotifications = $userNotifications->merge($companyNotifications)->sortByDesc('created_at');
            }
        }
        
        // Paginate manually
        $perPage = $request->query('per_page', 20);
        $page = $request->query('page', 1);
        $offset = ($page - 1) * $perPage;
        
        $paginatedNotifications = $allNotifications->slice($offset, $perPage)->values();
        $total = $allNotifications->count();
        
        return response()->json([
            'data' => $paginatedNotifications,
            'current_page' => (int)$page,
            'per_page' => (int)$perPage,
            'total' => $total,
            'last_page' => ceil($total / $perPage),
        ]);
    }

    /**
     * Mark a specific notification as read (supports both user and company notifications).
     */
    public function markAsRead(Request $request, string $id): JsonResponse
    {
        $userId = $request->input('user_id');
        $companyId = $request->input('company_id');
        
        if (!$userId) {
            return response()->json(['message' => 'User ID is required'], 400);
        }

        // Try to find notification in user's notifications first
        $user = User::findOrFail($userId);
        $notification = $user->notifications()->find($id);
        
        // If not found in user's notifications, try company notifications
        if (!$notification && $companyId) {
            $company = \App\Domain\Company\Models\Company::find($companyId);
            if ($company) {
                $notification = $company->notifications()->find($id);
            }
        }
        
        if (!$notification) {
            return response()->json(['message' => 'Notification not found'], 404);
        }
        
        $notification->markAsRead();

        return response()->json(['message' => 'Notification marked as read']);
    }

    /**
     * Mark all notifications as read for the user.
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        $userId = $request->input('user_id');
        $companyId = $request->input('company_id');
        
        if (!$userId) {
            return response()->json(['message' => 'User ID is required'], 400);
        }

        $user = \App\Domain\Auth\Models\User::findOrFail($userId);
        $user->unreadNotifications->markAsRead();
        
        if ($companyId) {
            $company = \App\Domain\Company\Models\Company::find($companyId);
            if ($company) {
                $company->unreadNotifications->markAsRead();
            }
        }

        return response()->json(['message' => 'All notifications marked as read']);
    }
}
