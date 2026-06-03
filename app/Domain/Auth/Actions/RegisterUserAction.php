<?php

namespace App\Domain\Auth\Actions;

use App\Domain\Auth\Repositories\UserRepositoryInterface;
use App\Domain\Access\Actions\AssignRoleAction;
use Illuminate\Support\Facades\Log;
use App\Support\WhatsappNumber;
use App\Support\OtpStore;
use Illuminate\Validation\ValidationException;

class RegisterUserAction
{
    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
        private readonly CreateUserTokenAction $tokenAction,
        private readonly AssignRoleAction $assignRoleAction
    ) {}

    /**
     * Register a new user in the system.
     * Company creation is deferred until user completes onboarding.
     *
     * @param array $data Input fields: name, email, password, role, whatsapp, device_name
     * @return array
     * @throws ValidationException
     */
    public function execute(array $data): array
    {
        $whatsapp = WhatsappNumber::normalize($data['whatsapp'] ?? '');

        if ($whatsapp && !OtpStore::isVerified($whatsapp)) {
            throw ValidationException::withMessages([
                'whatsapp' => ['Nomor WhatsApp belum terverifikasi dengan OTP.'],
            ]);
        }

        $data['whatsapp'] = $whatsapp;
        $user = $this->userRepository->create($data);

        try {
            // Set user role - use provided role or default to manager
            $role = $data['role'] ?? 'manager';

            // Assign role via the new Access domain
            $this->assignRoleAction->execute($user, $role);

            Log::info('User registered successfully', [
                'user_id' => $user->id,
                'email' => $user->email,
                'role' => $role,
                'note' => 'Company will be created during onboarding'
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to update user role', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
        }

        if ($whatsapp) {
            OtpStore::consumeVerified($whatsapp);
        }

        $token = $this->tokenAction->execute(
            $user, 
            $data['device_name'] ?? 'Web Browser', 
            false
        );

        $userData = $user->toArray();
        $userData['token'] = $token;

        return ['user' => $userData];
    }
}

