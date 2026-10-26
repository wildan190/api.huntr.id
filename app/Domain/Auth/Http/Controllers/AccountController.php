<?php

namespace App\Domain\Auth\Http\Controllers;

use App\Domain\Auth\Actions\GetUserSessionsAction;
use App\Domain\Auth\Actions\LogoutSessionAction;
use App\Domain\Auth\Actions\UpdateUserPasswordAction;
use App\Domain\Auth\Actions\UpdateUserWhatsappAction;
use App\Domain\Auth\Http\Requests\UpdatePasswordRequest;
use App\Domain\Auth\Http\Requests\UpdateUserProfileRequest;
use App\Domain\Auth\Http\Requests\UpdateWhatsappRequest;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AccountController extends Controller
{
    /**
     * Update the user's password.
     */
    public function updatePassword(UpdatePasswordRequest $request, UpdateUserPasswordAction $action): JsonResponse
    {
        $action->execute($request->user(), $request->input('password'));

        return response()->json([
            'message' => 'Password successfully updated.',
        ]);
    }

    /**
     * Update the user's WhatsApp number.
     */
    public function updateWhatsapp(UpdateWhatsappRequest $request, UpdateUserWhatsappAction $action): JsonResponse
    {
        $user = $action->execute($request->user(), $request->input('whatsapp'));

        return response()->json([
            'message' => 'WhatsApp number successfully updated.',
            'user' => $user,
        ]);
    }

    /**
     * Get authenticated user's profile.
     */
    public function getProfile(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->loadMissing('roles');
        $disk = config('filesystems.default');

        $profilePhotoUrl = null;
        if ($user->profile_photo_path) {
            $profilePhotoUrl = Storage::disk($disk)->url($user->profile_photo_path);
        }

        return response()->json([
            'user' => array_merge($user->toArray(), [
                'role' => $user->role,
                'roles' => $user->roles->map(fn($r) => ['id' => $r->id, 'name' => $r->name, 'slug' => $r->slug]),
                'profile_photo_url' => $profilePhotoUrl,
            ]),
        ]);
    }

    /**
     * Update authenticated user's profile data.
     * Handles both JSON and multipart/form-data (for photo upload).
     */
    public function updateProfile(UpdateUserProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $disk = config('filesystems.default');

        $data = $request->only([
            'name', 'email', 'nik', 'npwp', 'phone',
            'birth_place', 'birth_date', 'job_title',
            'country', 'province', 'city', 'district',
            'postal_code', 'full_address',
        ]);

        // Handle profile photo upload
        if ($request->hasFile('profile_photo') && $request->file('profile_photo')->isValid()) {
            // Delete old photo
            if ($user->profile_photo_path) {
                Storage::disk($disk)->delete($user->profile_photo_path);
            }
            $path = $request->file('profile_photo')->storePublicly('profile_photos', $disk);
            $data['profile_photo_path'] = $path;
        }

        $user->update($data);
        $user->refresh();
        $user->loadMissing('roles');

        $profilePhotoUrl = null;
        if ($user->profile_photo_path) {
            $profilePhotoUrl = Storage::disk($disk)->url($user->profile_photo_path);
        }

        return response()->json([
            'message' => 'Profile successfully updated.',
            'user' => array_merge($user->toArray(), [
                'role' => $user->role,
                'roles' => $user->roles->map(fn($r) => ['id' => $r->id, 'name' => $r->name, 'slug' => $r->slug]),
                'profile_photo_url' => $profilePhotoUrl,
            ]),
        ]);
    }

    /**
     * Get the user's active sessions.
     */
    public function getSessions(Request $request, GetUserSessionsAction $action): JsonResponse
    {
        $currentSessionId = $request->hasSession() ? $request->session()->getId() : null;
        $sessions = $action->execute($request->user(), $currentSessionId);

        return response()->json([
            'sessions' => $sessions,
        ]);
    }

    /**
     * Terminate a specific session.
     */
    public function logoutSession(Request $request, LogoutSessionAction $action, string $sessionId): JsonResponse
    {
        $action->execute($request->user(), $sessionId);

        return response()->json([
            'message' => 'Session successfully terminated.',
        ]);
    }
}
