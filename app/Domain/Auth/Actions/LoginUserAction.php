<?php

namespace App\Domain\Auth\Actions;

use App\Domain\Auth\Repositories\UserRepositoryInterface;
use App\Domain\Auth\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Auth;

class LoginUserAction
{
    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
        private readonly CreateUserTokenAction $tokenAction
    ) {}

    /**
     * Authenticate user credentials.
     *
     * If 2FA is active and confirmed, returns:
     *   { two_factor: true, two_factor_challenge_token: '...' }
     *
     * Otherwise returns the full user + token payload.
     *
     * @throws ValidationException
     */
    public function execute(array $credentials): array
    {
        $login = $credentials['login'] ?? $credentials['email'] ?? $credentials['whatsapp'] ?? '';
        $user  = $this->userRepository->findByEmailOrWhatsapp($login);

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Invalid credentials.'],
            ]);
        }

        // --- 2FA gate ---
        if ($this->userHas2FAEnabled($user)) {
            $challengeToken = $this->createChallengeToken(
                $user,
                $credentials['device_name'] ?? 'Web Browser',
                (bool) ($credentials['remember_me'] ?? false)
            );

            return [
                'two_factor'                 => true,
                'two_factor_challenge_token' => $challengeToken,
            ];
        }

        // No 2FA — issue full token immediately
        return $this->issueFullToken($user, $credentials);
    }

    /**
     * True when the user has set up and confirmed 2FA.
     */
    private function userHas2FAEnabled(User $user): bool
    {
        return !empty($user->two_factor_secret)
            && !empty($user->two_factor_confirmed_at);
    }

    /**
     * Store a short-lived challenge token (10 minutes) and return its value.
     */
    private function createChallengeToken(User $user, string $deviceName, bool $rememberMe): string
    {
        // Clean up stale tokens for this user first
        DB::table('two_factor_pending_tokens')
            ->where('user_id', $user->id)
            ->where('expires_at', '<', now())
            ->delete();

        $token = Str::random(64);

        DB::table('two_factor_pending_tokens')->insert([
            'user_id'     => $user->id,
            'token'       => $token,
            'device_name' => $deviceName,
            'remember_me' => $rememberMe,
            'expires_at'  => now()->addMinutes(10),
            'created_at'  => now(),
        ]);

        return $token;
    }

    /**
     * Issue the real Sanctum token after all checks pass.
     * Called directly (no 2FA) or by Verify2FALoginAction after TOTP success.
     */
    public function issueFullToken(User $user, array $credentials): array
    {
        // Fix roles before issuing token
        try {
            $user->ensureCompanyOwnerRole();
            $user->refresh();
        } catch (\Exception $e) {
            \Log::warning('Role fix failed during login', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);
        }

        Auth::setUser($user);

        $token = $this->tokenAction->execute(
            $user,
            $credentials['device_name'] ?? 'Web Browser',
            (bool) ($credentials['remember_me'] ?? false)
        );

        $userData          = $user->toArray();
        $userData['token'] = $token;

        return ['user' => $userData];
    }
}

