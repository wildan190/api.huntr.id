<?php

namespace App\Domain\Auth\Http\Controllers;

use App\Domain\Auth\Actions\RegisterUserAction;
use App\Domain\Auth\Actions\LoginUserAction;
use App\Domain\Auth\Actions\SendOtpAction;
use App\Domain\Auth\Actions\VerifyOtpAction;
use App\Domain\Auth\Http\Requests\RegisterUserRequest;
use App\Domain\Auth\Http\Requests\LoginUserRequest;
use App\Domain\Auth\Http\Requests\SendOtpRequest;
use App\Domain\Auth\Http\Requests\VerifyOtpRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AuthController
 * 
 * Responsibility: Manages authentication and OTP requests.
 * Pattern: Thin Controller.
 */
class AuthController extends \App\Http\Controllers\Controller
{
    /**
     * Register a new user.
     */
    public function register(RegisterUserRequest $request, RegisterUserAction $action): JsonResponse
    {
        return response()->json($action->execute($request->validated()), 201);
    }

    /**
     * Perform user login.
     */
    public function login(LoginUserRequest $request, LoginUserAction $action): JsonResponse
    {
        return response()->json($action->execute($request->validated()));
    }

    /**
     * Verify 2FA code after password login challenge.
     * Exchanges the short-lived challenge token for a real Sanctum token.
     */
    public function verifyTwoFactor(
        Request $request,
        \App\Domain\Auth\Actions\Verify2FALoginAction $action
    ): JsonResponse {
        $request->validate([
            'two_factor_challenge_token' => 'required|string',
            'code'                       => 'nullable|string',
            'recovery_code'              => 'nullable|string',
        ]);

        try {
            $result = $action->execute($request->only([
                'two_factor_challenge_token',
                'code',
                'recovery_code',
            ]));
            return response()->json($result);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        }
    }

    /**
     * Send OTP code via WhatsApp.
     */
    public function sendOtp(SendOtpRequest $request, SendOtpAction $action): JsonResponse
    {
        return response()->json($action->execute($request->input('whatsapp')));
    }

    /**
     * Verify the sent OTP code.
     */
    public function verifyOtp(VerifyOtpRequest $request, VerifyOtpAction $action): JsonResponse
    {
        return response()->json($action->execute($request->validated()));
    }

    /**
     * Revoke the current API token.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logged out successfully.']);
    }

    /**
     * Reset user password via WhatsApp OTP.
     */
    public function resetPassword(
        \App\Domain\Auth\Http\Requests\ResetPasswordWhatsappRequest $request, 
        \App\Domain\Auth\Actions\ResetPasswordViaWhatsappAction $action
    ): JsonResponse {
        return response()->json($action->execute($request->validated()));
    }
}
