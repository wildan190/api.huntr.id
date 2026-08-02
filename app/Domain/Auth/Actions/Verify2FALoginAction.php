<?php

namespace App\Domain\Auth\Actions;

use App\Domain\Auth\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;

/**
 * Verify2FALoginAction
 *
 * Validates the TOTP code (or recovery code) against the pending 2FA challenge token,
 * then exchanges it for a real Sanctum API token.
 *
 * Flow:
 *   1. Client sends: { two_factor_challenge_token, code } or { two_factor_challenge_token, recovery_code }
 *   2. We look up the pending token, verify it hasn't expired, find the user.
 *   3. We validate the TOTP / recovery code using Fortify's built-in logic.
 *   4. We delete the pending token and issue a full Sanctum token via LoginUserAction.
 */
class Verify2FALoginAction
{
    public function __construct(
        private readonly LoginUserAction $loginAction,
        private readonly CreateUserTokenAction $tokenAction
    ) {}

    /**
     * @throws ValidationException
     */
    public function execute(array $data): array
    {
        $challengeToken = $data['two_factor_challenge_token'] ?? '';
        $code           = $data['code'] ?? null;
        $recoveryCode   = $data['recovery_code'] ?? null;

        if (!$challengeToken) {
            throw ValidationException::withMessages([
                'two_factor_challenge_token' => ['Challenge token is required.'],
            ]);
        }

        // 1. Look up the pending token
        $pending = DB::table('two_factor_pending_tokens')
            ->where('token', $challengeToken)
            ->where('expires_at', '>', now())
            ->first();

        if (!$pending) {
            throw ValidationException::withMessages([
                'two_factor_challenge_token' => ['Challenge token is invalid or has expired. Please log in again.'],
            ]);
        }

        // 2. Load the user
        $user = User::find($pending->user_id);

        if (!$user) {
            DB::table('two_factor_pending_tokens')->where('token', $challengeToken)->delete();
            throw ValidationException::withMessages([
                'two_factor_challenge_token' => ['User not found.'],
            ]);
        }

        // 3. Validate TOTP code or recovery code
        if ($recoveryCode) {
            $this->validateRecoveryCode($user, $recoveryCode);
        } elseif ($code) {
            $this->validateTotpCode($user, $code);
        } else {
            throw ValidationException::withMessages([
                'code' => ['A verification code or recovery code is required.'],
            ]);
        }

        // 4. Consume the pending token (one-time use)
        DB::table('two_factor_pending_tokens')->where('token', $challengeToken)->delete();

        // 5. Issue the real Sanctum token
        return $this->loginAction->issueFullToken($user, [
            'device_name' => $pending->device_name ?? 'Web Browser',
            'remember_me' => (bool) $pending->remember_me,
        ]);
    }

    /**
     * Validate a TOTP code using Fortify's Google2FA integration.
     *
     * @throws ValidationException
     */
    private function validateTotpCode(User $user, string $code): void
    {
        $google2fa = app(\PragmaRX\Google2FA\Google2FA::class);

        $secret = decrypt($user->two_factor_secret);

        $valid = $google2fa->verifyKey($secret, $code);

        if (!$valid) {
            throw ValidationException::withMessages([
                'code' => ['The provided two-factor authentication code was invalid.'],
            ]);
        }
    }

    /**
     * Validate a recovery code, consuming it on success.
     *
     * @throws ValidationException
     */
    private function validateRecoveryCode(User $user, string $recoveryCode): void
    {
        $codes = json_decode(decrypt($user->two_factor_recovery_codes), true) ?? [];

        $matched = collect($codes)->first(fn($c) => hash_equals($c, $recoveryCode));

        if (!$matched) {
            throw ValidationException::withMessages([
                'recovery_code' => ['The provided two-factor recovery code was invalid.'],
            ]);
        }

        // Consume the used recovery code
        $remaining = collect($codes)->reject(fn($c) => hash_equals($c, $recoveryCode))->values()->all();
        $user->forceFill([
            'two_factor_recovery_codes' => encrypt(json_encode($remaining)),
        ])->save();
    }
}
