<?php

namespace App\Domain\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;

/**
 * 2FA controller that works with Bearer token authentication (Sanctum/API guard).
 * This bypasses Fortify's web-only CSRF-protected routes.
 */
class TwoFactorController extends Controller
{
    /**
     * Enable two-factor authentication for the authenticated user.
     *
     * POST /api/account/two-factor-authentication
     */
    public function enable(
        Request $request,
        EnableTwoFactorAuthentication $enable
    ): JsonResponse {
        $enable($request->user());

        return response()->json([
            'message' => 'Two-factor authentication has been enabled.',
        ], 200);
    }

    /**
     * Disable two-factor authentication for the authenticated user.
     *
     * DELETE /api/account/two-factor-authentication
     */
    public function disable(
        Request $request,
        DisableTwoFactorAuthentication $disable
    ): JsonResponse {
        $disable($request->user());

        return response()->json([
            'message' => 'Two-factor authentication has been disabled.',
        ], 200);
    }

    /**
     * Confirm and finalize two-factor authentication setup.
     *
     * POST /api/account/two-factor-authentication/confirm
     */
    public function confirm(
        Request $request,
        ConfirmTwoFactorAuthentication $confirm
    ): JsonResponse {
        $request->validate(['code' => 'required|string']);

        $confirm($request->user(), $request->input('code'));

        return response()->json([
            'message' => 'Two-factor authentication confirmed successfully.',
        ], 200);
    }

    /**
     * Get the two-factor authentication QR code SVG.
     *
     * GET /api/account/two-factor-qr-code
     */
    public function qrCode(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->two_factor_secret) {
            return response()->json(['message' => '2FA has not been enabled yet.'], 422);
        }

        return response()->json([
            'svg' => $user->twoFactorQrCodeSvg(),
        ]);
    }

    /**
     * Get the recovery codes for two-factor authentication.
     *
     * GET /api/account/two-factor-recovery-codes
     */
    public function recoveryCodes(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json(
            json_decode(decrypt($user->two_factor_recovery_codes), true) ?? []
        );
    }

    /**
     * Regenerate the recovery codes for two-factor authentication.
     *
     * POST /api/account/two-factor-recovery-codes
     */
    public function regenerateRecoveryCodes(
        Request $request,
        GenerateNewRecoveryCodes $generate
    ): JsonResponse {
        $generate($request->user());

        return response()->json([
            'message' => 'Recovery codes regenerated.',
        ]);
    }
}
