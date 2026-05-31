<?php

namespace App\Domain\Auth\Actions;

use App\Domain\Auth\Repositories\UserRepositoryInterface;
use App\Domain\Auth\Models\User;
use App\Domain\Company\Models\Company;
use Illuminate\Support\Facades\Log;

class RegisterUserAction
{
    public function __construct(
        private readonly UserRepositoryInterface $userRepository
    ) {}

    /**
     * Register a new user in the system and create a default company.
     *
     * @param array $data Input fields: name, email, password, role, whatsapp
     * @return User
     */
    public function execute(array $data): User
    {
        $user = $this->userRepository->create($data);

        try {
            // Create a default company for the user with 'approved' status
            // User can then complete onboarding and submit for approval when ready
            $company = Company::create([
                'owner_id' => $user->id,
                'name' => $data['name'] . '\'s Company',
                'type' => 'buyer', // Default to buyer, can be changed during onboarding
                'status' => 'approved', // Start as approved, user can submit for verification later
                'email' => $data['email'] ?? null,
                'phone' => $data['whatsapp'] ?? null,
            ]);

            // Associate user with the company
            $user->update([
                'company_id' => $company->id,
                'role' => 'manager'
            ]);

            Log::info('Created default company for user', [
                'user_id' => $user->id,
                'company_id' => $company->id,
                'company_status' => $company->status
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to create default company', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
            // Don't throw, user is already created
        }

        return $user;
    }
}

