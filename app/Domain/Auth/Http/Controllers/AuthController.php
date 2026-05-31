<?php

namespace App\Domain\Auth\Http\Controllers;

use App\Domain\Auth\Actions\RegisterUserAction;
use App\Domain\Auth\Actions\LoginUserAction;
use App\Domain\Auth\Http\Requests\RegisterUserRequest;
use App\Domain\Auth\Http\Requests\LoginUserRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Domain\Communication\Actions\SendWhatsAppVerificationAction;
use App\Domain\Auth\Models\User;
use App\Support\WhatsappNumber;
use App\Support\OtpStore;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class AuthController extends \App\Http\Controllers\Controller
{
    public function register(RegisterUserRequest $request, RegisterUserAction $action): JsonResponse
    {
        $whatsapp = WhatsappNumber::normalize($request->input('whatsapp'));

        if ($whatsapp === '') {
            return response()->json(['message' => 'Nomor WhatsApp tidak valid.'], 422);
        }

        // Enforce OTP verification before allowing registration
        if (! OtpStore::isVerified($whatsapp)) {
            return response()->json(['message' => 'Nomor WhatsApp belum terverifikasi dengan OTP.'], 422);
        }

        $data = $request->validated();
        $data['whatsapp'] = $whatsapp;

        $user = $action->execute($data);

        OtpStore::consumeVerified($whatsapp);
        
        return response()->json(['user' => $user], 201);
    }

    public function login(LoginUserRequest $request, LoginUserAction $action): JsonResponse
    {
        $user = $action->execute($request->input('email'), $request->input('password'));
        
        // Log the user in to the session so it's recorded in the sessions table
        Auth::login($user);
        
        return response()->json(['user' => $user]);
    }

    public function sendOtp(Request $request, SendWhatsAppVerificationAction $sendWhatsAppAction): JsonResponse
    {
        Log::info('sendOtp called', [
            'method' => $request->method(),
            'path' => $request->path(),
            'url' => $request->url(),
            'input' => $request->all(),
        ]);

        $request->validate([
            'whatsapp' => ['required', 'string'],
        ]);

        $whatsapp = WhatsappNumber::normalize($request->input('whatsapp'));

        if (! WhatsappNumber::isValid($whatsapp)) {
            return response()->json([
                'message' => 'Format nomor WhatsApp tidak valid. Gunakan format 08xxxxxxxxxx (contoh: 085156334793).',
            ], 422);
        }

        $issued = OtpStore::issue($whatsapp);
        $otp = $issued['otp'];
        $otpToken = $issued['token'];

        $message = "Kode OTP Huntr.id Anda adalah: {$otp}. Berlaku selama 10 menit. Jangan sebarkan kode ini.";

        $delivery = $sendWhatsAppAction->execute($whatsapp, $message, false);

        $response = [
            'message' => ($delivery['ok'] ?? false)
                ? 'OTP berhasil dikirim ke nomor WhatsApp Anda.'
                : 'OTP dibuat, tetapi pengiriman WhatsApp gagal. Gunakan kode debug di bawah (mode development) atau coba lagi.',
            'expires_in' => OtpStore::ttlSeconds(),
            'whatsapp' => $whatsapp,
            'otp_token' => $otpToken,
            'whatsapp_sent' => (bool) ($delivery['ok'] ?? false),
        ];

        if (! ($delivery['ok'] ?? false) && app()->environment('local')) {
            $response['delivery_error'] = $delivery['detail'] ?? 'unknown';
        }

        if (app()->environment('local') || config('app.debug')) {
            $response['otp'] = (string) $otp;
        }

        return response()->json($response);
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        $request->validate([
            'whatsapp' => ['nullable', 'string'],
            'otp' => ['required', 'string'],
            'otp_token' => ['nullable', 'string'],
        ]);

        $otp = preg_replace('/\D/', '', trim($request->input('otp')));
        $otpToken = trim((string) $request->input('otp_token', ''));

        if (strlen($otp) !== 6) {
            return response()->json(['message' => 'Kode OTP harus 6 digit.'], 422);
        }

        $whatsapp = null;

        if ($otpToken !== '') {
            $whatsapp = OtpStore::verifyByToken($otpToken, $otp);
            if ($whatsapp === null) {
                if (! OtpStore::hasPendingToken($otpToken)) {
                    return response()->json(['message' => 'Kode OTP telah kedaluwarsa. Silakan minta kode baru.'], 422);
                }

                return response()->json(['message' => 'Kode OTP tidak sesuai. Periksa kembali kode dari WhatsApp.'], 422);
            }
        } else {
            $whatsapp = WhatsappNumber::normalize($request->input('whatsapp', ''));

            if (! WhatsappNumber::isValid($whatsapp)) {
                return response()->json(['message' => 'Format nomor WhatsApp tidak valid.'], 422);
            }

            if (! OtpStore::hasPending($whatsapp)) {
                return response()->json(['message' => 'Kode OTP telah kedaluwarsa. Silakan minta kode baru.'], 422);
            }

            if (! OtpStore::verify($whatsapp, $otp)) {
                return response()->json(['message' => 'Kode OTP tidak sesuai. Periksa kembali kode dari WhatsApp.'], 422);
            }
        }

        OtpStore::markVerified($whatsapp);

        return response()->json([
            'message' => 'Nomor WhatsApp berhasil diverifikasi.',
            'verified' => true,
            'whatsapp' => $whatsapp,
        ]);
    }
}
