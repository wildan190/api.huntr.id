<?php

namespace App\Domain\Auth\Actions;

use App\Domain\Auth\Repositories\UserRepositoryInterface;
use App\Domain\Auth\Models\User;
use Illuminate\Support\Facades\Log;

class RegisterUserAction
{
    public function __construct(
        private readonly UserRepositoryInterface $userRepository
    ) {}

    /**
     * Register a new user in the system.
     * Company creation is deferred until user completes onboarding.
     *
     * @param array $data Input fields: name, email, password, role, whatsapp
     * @return User
     */
    public function execute(array $data): User
    {
        $user = $this->userRepository->create($data);

        try {
            // Set user as manager role by default
            // Company will be created during onboarding completion
            $user->update([
                'role' => 'manager'
            ]);

            Log::info('User registered successfully', [
                'user_id' => $user->id,
                'email' => $user->email,
                'note' => 'Company will be created during onboarding'
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to update user role', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
            // Don't throw, user is already created
        }

        return $user;
    }
}

