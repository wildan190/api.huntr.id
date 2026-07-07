<?php

namespace App\Domain\Auth\Actions;

use App\Domain\Auth\Repositories\UserRepositoryInterface;
use App\Domain\Auth\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Auth;

class LoginUserAction
{
    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
        private readonly CreateUserTokenAction $tokenAction
    ) {}

    /**
     * Authenticate user credentials and return user with token.
     *
     * @param array $credentials
     * @return array
     * @throws ValidationException
     */
    public function execute(array $credentials): array
    {
        $login = $credentials['login'] ?? $credentials['email'] ?? $credentials['whatsapp'] ?? '';
        $user = $this->userRepository->findByEmailOrWhatsapp($login);

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Invalid credentials.'],
            ]);
        }

        // Critical: Fix user roles BEFORE creating token and returning data
        try {
            $user->ensureCompanyOwnerRole();
            $user->refresh(); // Ensure we have fresh data from DB
        } catch (\Exception $e) {
            \Log::warning('Role fix failed during login', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
        }
        
        Auth::setUser($user);

        $token = $this->tokenAction->execute(
            $user, 
            $credentials['device_name'] ?? 'Web Browser', 
            $credentials['remember_me'] ?? false
        );
        
        $userData = $user->toArray();
        $userData['token'] = $token;

        return ['user' => $userData];
    }
}
