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
     * Update company details during onboarding and submit for approval.
     * Changes company status from 'approved' to 'pending' to request admin verification.
     *
     * @param User $user The user updating the company
     * @param array $data Input fields: name, type (buyer/vendor), and additional details
     * @return Company
     */
    public function execute(User $user, array $data): Company
    {
        Log::info('RegisterCompanyAction data:', $data);

        // Get the user's existing company
        $company = $user->company ?? Company::where('owner_id', $user->id)->first();

        if (!$company) {
            // If no company exists, create one (shouldn't happen in normal flow)
            $companyData = array_merge($data, [
                'status'   => 'pending',
                'owner_id' => $user->id,
            ]);
            $company = $this->companyRepository->create($companyData);
        } else {
            // Update existing company and change status to 'pending' to request approval
            $updateData = array_merge($data, [
                'status' => 'pending', // Submit for approval after onboarding
            ]);
            $company->update($updateData);
        }

        Log::info('Updated company ID: ' . $company->id, [
            'status' => $company->status,
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

        return $company;
    }
}
