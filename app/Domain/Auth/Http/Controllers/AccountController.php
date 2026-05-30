<?php

namespace App\Domain\Auth\Http\Controllers;

use App\Domain\Auth\Http\Requests\UpdatePasswordRequest;
use App\Domain\Auth\Http\Requests\UpdateWhatsappRequest;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;

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
        $whatsapp = $request->input('whatsapp');

        // Enforce OTP verification before allowing update
        if (!Cache::get('otp_verified_' . $whatsapp)) {
            return response()->json([
                'message' => 'Nomor WhatsApp baru belum terverifikasi dengan OTP.',
            ], 422);
        }

        $user->update([
            'whatsapp' => $whatsapp,
        ]);

        // Consume the verification token
        Cache::forget('otp_verified_' . $whatsapp);

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
        $currentSessionId = $request->hasSession() ? $request->session()->getId() : null;

        $sessions = DB::table('sessions')
            ->where('user_id', $request->user()->id)
            ->orderBy('last_activity', 'desc')
            ->get()
            ->map(function ($session) use ($currentSessionId) {
                return [
                    'id' => $session->id,
                    'ip_address' => $session->ip_address,
                    'user_agent' => $session->user_agent,
                    'is_current_device' => $session->id === $currentSessionId,
                    'last_active' => date('Y-m-d H:i:s', $session->last_activity),
                ];
            });

        return response()->json([
            'sessions' => $sessions,
        ]);
    }

    /**
     * Terminate a specific session.
     */
    public function logoutSession(Request $request, string $sessionId): JsonResponse
    {
        DB::table('sessions')
            ->where('user_id', $request->user()->id)
            ->where('id', $sessionId)
            ->delete();

        return response()->json([
            'message' => 'Sesi berhasil dihentikan.',
        ]);
    }
}
