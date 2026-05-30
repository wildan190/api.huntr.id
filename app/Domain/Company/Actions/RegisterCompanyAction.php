<?php

namespace App\Domain\Company\Actions;

use App\Domain\Auth\Repositories\UserRepositoryInterface;
use App\Domain\Company\Repositories\CompanyRepositoryInterface;
use App\Domain\Company\Models\Company;
use App\Domain\Auth\Models\User;
use Illuminate\Support\Facades\Log;

class RegisterCompanyAction
{
    public function __construct(
        private readonly CompanyRepositoryInterface $companyRepository,
        private readonly UserRepositoryInterface $userRepository
    ) {}

    /**
     * Register a new company and associate the registering user with it.
     *
     * @param User $user The registering user
     * @param array $data Input fields: name, type (buyer/vendor), and additional details
     * @return Company
     */
    public function execute(User $user, array $data): Company
    {
        Log::info('RegisterCompanyAction data:', $data);

        $companyData = array_merge($data, [
            'status'   => 'pending',
            'owner_id' => $user->id,
        ]);

        $company = $this->companyRepository->create($companyData);

        Log::info('Created company ID: ' . $company->id, [
            'about' => $company->about,
            'industry_type' => $company->industry_type
        ]);

        if (!empty($data['documents'])) {
            foreach ($data['documents'] as $doc) {
                $company->documents()->create([
                    'name'      => $doc['name'],
                    'type'      => $doc['type'],
                    'file_path' => $doc['file_path'] ?? null,
                ]);
            }
        }

        // Set user as manager/owner role for the company they just created
        $this->userRepository->update($user, [
            'company_id' => $company->id,
            'role'       => 'manager' // Using 'manager' as it usually has higher permissions
        ]);

        return $company;
    }
}
