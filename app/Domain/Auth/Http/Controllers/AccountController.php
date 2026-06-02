<?php

namespace App\Domain\Auth\Http\Controllers;

use App\Domain\Auth\Http\Requests\UpdatePasswordRequest;
use App\Domain\Auth\Http\Requests\UpdateWhatsappRequest;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Support\WhatsappNumber;
use App\Support\OtpStore;

class AccountController extends Controller
{
    /**
     * Update the user's password.
     */
    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        $user->update([
            'password' => Hash::make($request->input('password')),
        ]);

        return response()->json([
            'message' => 'Password berhasil diperbarui.',
        ]);
    }

    /**
     * Update the user's WhatsApp number.
     */
    public function updateWhatsapp(UpdateWhatsappRequest $request): JsonResponse
    {
        $user = $request->user();
        
        $whatsapp = WhatsappNumber::normalize($request->input('whatsapp'));

        if ($whatsapp === '') {
            return response()->json([
                'message' => 'Nomor WhatsApp tidak valid.',
            ], 422);
        }

        // Enforce OTP verification before allowing update
        if (! OtpStore::isVerified($whatsapp)) {
            return response()->json([
                'message' => 'Nomor WhatsApp baru belum terverifikasi dengan OTP.',
            ], 422);
        }

        $user->update([
            'whatsapp' => $whatsapp,
        ]);

        OtpStore::consumeVerified($whatsapp);

        return response()->json([
            'message' => 'Nomor WhatsApp berhasil diperbarui.',
            'user' => $user,
        ]);
    }

    /**
     * Get the user's active sessions.
     */
    public function getSessions(Request $request): JsonResponse
    {
        $user = $request->user();
        $currentSessionId = $request->hasSession() ? $request->session()->getId() : null;

        // 1. Get database sessions (if any)
        $dbSessions = DB::table('sessions')
            ->where('user_id', $user->id)
            ->orderBy('last_activity', 'desc')
            ->get()
            ->map(function ($session) use ($currentSessionId) {
                return [
                    'id' => $session->id,
                    'type' => 'Web Session',
                    'ip_address' => $session->ip_address,
                    'user_agent' => $session->user_agent,
                    'is_current_device' => $session->id === $currentSessionId,
                    'last_active' => date('Y-m-d H:i:s', $session->last_activity),
                ];
            });

        // 2. Get personal access tokens (Sanctum)
        $tokens = $user->tokens()
            ->orderBy('last_used_at', 'desc')
            ->get()
            ->map(function ($token) {
                return [
                    'id' => $token->id,
                    'type' => 'API Token',
                    'name' => $token->name,
                    'ip_address' => 'N/A', // Sanctum doesn't store IP by default
                    'user_agent' => 'Bearer Token',
                    'is_current_device' => false, // Hard to determine without session ID
                    'last_active' => $token->last_used_at ? $token->last_used_at->toDateTimeString() : 'Never used',
                ];
            });

        return response()->json([
            'sessions' => $dbSessions->concat($tokens),
        ]);
    }

    /**
     * Terminate a specific session.
     */
    public function logoutSession(Request $request, string $sessionId): JsonResponse
    {
        $user = $request->user();

        // 1. Try deleting from database sessions
        $deleted = DB::table('sessions')
            ->where('user_id', $user->id)
            ->where('id', $sessionId)
            ->delete();

        // 2. If not found in sessions, try deleting from Sanctum tokens
        if (!$deleted && is_numeric($sessionId)) {
            $user->tokens()->where('id', $sessionId)->delete();
        }

        return response()->json([
            'message' => 'Sesi berhasil dihentikan.',
        ]);
    }
}
