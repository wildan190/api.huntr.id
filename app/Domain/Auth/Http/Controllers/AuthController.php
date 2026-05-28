<?php

namespace App\Domain\Auth\Http\Controllers;

use App\Domain\Auth\Actions\RegisterUserAction;
use App\Domain\Auth\Actions\LoginUserAction;
use App\Domain\Auth\Http\Requests\RegisterUserRequest;
use App\Domain\Auth\Http\Requests\LoginUserRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use App\Domain\Communication\Actions\SendWhatsAppVerificationAction;
use App\Domain\Auth\Models\User;

class AuthController extends \App\Http\Controllers\Controller
{
    public function register(RegisterUserRequest $request, RegisterUserAction $action): JsonResponse
    {
        $whatsapp = $request->input('whatsapp');
        
        // Enforce OTP verification before allowing registration
        if (!Cache::get('otp_verified_' . $whatsapp)) {
            return response()->json(['message' => 'Nomor WhatsApp belum terverifikasi dengan OTP.'], 422);
        }
        
        $user = $action->execute($request->validated());
        
        // Consume the verification token
        Cache::forget('otp_verified_' . $whatsapp);
        
        return response()->json(['user' => $user], 201);
    }

    public function login(LoginUserRequest $request, LoginUserAction $action): JsonResponse
    {
        $user = $action->execute($request->input('email'), $request->input('password'));
        return response()->json(['user' => $user]);
    }

    public function sendOtp(Request $request, SendWhatsAppVerificationAction $sendWhatsAppAction): JsonResponse
    {
        $request->validate([
            'whatsapp' => ['required', 'string'],
        ]);

        $whatsapp = $request->input('whatsapp');
        
        // Generate a 6-digit random OTP
        $otp = (string) rand(100000, 999999);
        
        // Store in cache for 10 minutes
        Cache::put('otp_' . $whatsapp, $otp, now()->addMinutes(10));
        
        $message = "Kode OTP Huntr.id Anda adalah: {$otp}. Berlaku selama 10 menit. Jangan sebarkan kode ini.";
        
        // Send synchronously via Fonnte
        $sendWhatsAppAction->execute($whatsapp, $message, false);
        
        $response = [
            'message' => 'OTP berhasil dikirim ke nomor WhatsApp Anda.',
        ];

        // Expose OTP in development/debug mode for ease of developer testing
        if (app()->environment('local') || config('app.debug')) {
            $response['otp'] = $otp;
        }

        return response()->json($response);
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        $request->validate([
            'whatsapp' => ['required', 'string'],
            'otp' => ['required', 'string'],
        ]);

        $whatsapp = $request->input('whatsapp');
        $otp = $request->input('otp');
        
        $cachedOtp = Cache::get('otp_' . $whatsapp);
        
        if (!$cachedOtp || $cachedOtp !== $otp) {
            return response()->json(['message' => 'Kode OTP tidak valid atau telah kedaluwarsa.'], 422);
        }
        
        // Store validation flag for 15 minutes
        Cache::put('otp_verified_' . $whatsapp, true, now()->addMinutes(15));
        
        // Invalidate OTP
        Cache::forget('otp_' . $whatsapp);

        return response()->json([
            'message' => 'Nomor WhatsApp berhasil diverifikasi.',
            'verified' => true
        ]);
    }
}
