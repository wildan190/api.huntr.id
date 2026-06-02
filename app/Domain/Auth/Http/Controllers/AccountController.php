<?php

namespace App\Domain\Auth\Http\Controllers;

use App\Domain\Auth\Actions\GetUserSessionsAction;
use App\Domain\Auth\Actions\LogoutSessionAction;
use App\Domain\Auth\Actions\UpdateUserPasswordAction;
use App\Domain\Auth\Actions\UpdateUserWhatsappAction;
use App\Domain\Auth\Http\Requests\UpdatePasswordRequest;
use App\Domain\Auth\Http\Requests\UpdateWhatsappRequest;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    /**
     * Update the user's password.
     */
    public function updatePassword(UpdatePasswordRequest $request, UpdateUserPasswordAction $action): JsonResponse
    {
        $action->execute($request->user(), $request->input('password'));

        return response()->json([
            'message' => 'Password berhasil diperbarui.',
        ]);
    }

    /**
     * Update the user's WhatsApp number.
     */
    public function updateWhatsapp(UpdateWhatsappRequest $request, UpdateUserWhatsappAction $action): JsonResponse
    {
        $user = $action->execute($request->user(), $request->input('whatsapp'));

        return response()->json([
            'message' => 'Nomor WhatsApp berhasil diperbarui.',
            'user' => $user,
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
            'message' => 'Sesi berhasil dihentikan.',
        ]);
    }
}
