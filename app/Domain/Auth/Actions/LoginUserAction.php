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
        $login = $credentials['email'] ?? $credentials['whatsapp'] ?? '';
        $user = $this->userRepository->findByEmailOrWhatsapp($login);

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Invalid credentials.'],
            ]);
        }

        Auth::login($user);

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
