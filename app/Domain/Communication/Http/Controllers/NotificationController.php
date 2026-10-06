<?php

namespace App\Domain\Communication\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Domain\Auth\Models\User;
use App\Domain\Company\Models\Company;
use App\Http\Controllers\Controller;

class NotificationController extends Controller
{
    /**
     * Determine if the authenticated user is authorized to access the given company.
     *
     * User is authorized if:
     * - The user is the company owner (company.owner_id === user.id)
     * - The user is a member of the company (user.company_id === company.id)
     * - The user owns the company via the companies() hasMany relation
     */
    private function userCanAccessCompany(User $user, Company $company): bool
    {
        if ($company->owner_id === $user->id) {
            return true;
        }

        if ($user->company_id === $company->id) {
            return true;
        }

        if ($user->companies()->where('id', $company->id)->exists()) {
            return true;
        }

        return false;
    }

    /**
     * Resolve and authorize the company from the request, if any.
     * Returns the Company instance if authorized, null if no company_id provided,
     * or aborts with 403 if company_id is provided but user is not authorized.
     */
    private function resolveAuthorizedCompany(Request $request, User $user): ?Company
    {
        $companyId = $request->input('company_id') ?? $request->query('company_id');

        if (!$companyId) {
            return null;
        }

        $company = Company::find($companyId);

        if (!$company) {
            return null;
        }

        if (!$this->userCanAccessCompany($user, $company)) {
            abort(403, 'You are not authorized to access notifications for this company.');
        }

        return $company;
    }

    /**
     * Get the authenticated user and validate any client-supplied user_id matches.
     * For backward compatibility, client may still send user_id, but it MUST match
     * the authenticated user's id. Otherwise, authorization is rejected.
     */
    private function resolveAuthenticatedUser(Request $request): User
    {
        $user = $request->user();

        if (!$user) {
            abort(401, 'Authentication required.');
        }

        $clientUserId = $request->input('user_id') ?? $request->query('user_id');

        if ($clientUserId && $clientUserId !== $user->id) {
            abort(403, 'You are not authorized to access notifications for another user.');
        }

        return $user;
    }

    /**
     * Get user's notifications (including company notifications).
     */
    public function index(Request $request): JsonResponse
    {
        $user = $this->resolveAuthenticatedUser($request);
        $company = $this->resolveAuthorizedCompany($request, $user);

        $userNotifications = $user->notifications()->latest()->get();

        $allNotifications = $userNotifications;

        if ($company) {
            $companyNotifications = $company->notifications()->latest()->get();
            $allNotifications = $userNotifications->merge($companyNotifications)->sortByDesc('created_at');
        }

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
        $user = $this->resolveAuthenticatedUser($request);
        $company = $this->resolveAuthorizedCompany($request, $user);

        $notification = $user->notifications()->find($id);

        if (!$notification && $company) {
            $notification = $company->notifications()->find($id);
        }

        if (!$notification) {
            return response()->json(['message' => 'Notification not found'], 404);
        }

        $notification->markAsRead();

        return response()->json(['message' => 'Notification marked as read']);
    }

    /**
     * Mark all notifications as read for the user and authorized company.
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        $user = $this->resolveAuthenticatedUser($request);
        $company = $this->resolveAuthorizedCompany($request, $user);

        $user->unreadNotifications->markAsRead();

        if ($company) {
            $company->unreadNotifications->markAsRead();
        }

        return response()->json(['message' => 'All notifications marked as read']);
    }

    /**
     * Delete (clear) all notifications for the user and authorized company.
     */
    public function clearAll(Request $request): JsonResponse
    {
        $user = $this->resolveAuthenticatedUser($request);
        $company = $this->resolveAuthorizedCompany($request, $user);

        $user->notifications()->delete();

        if ($company) {
            $company->notifications()->delete();
        }

        return response()->json(['message' => 'All notifications cleared']);
    }
}
