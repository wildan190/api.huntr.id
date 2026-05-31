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
     * Create company during onboarding and submit for approval.
     * Creates a new company with 'pending' status to request admin verification.
     *
     * @param User $user The user creating the company
     * @param array $data Input fields: name, type (buyer/vendor), and additional details
     * @return Company
     */
    public function execute(User $user, array $data): Company
    {
        Log::info('RegisterCompanyAction data:', $data);

        // Get the user's existing company
        $company = $user->company ?? Company::where('owner_id', $user->id)->first();

        if (!$company) {
            // Create new company during onboarding with 'pending' status
            $companyData = array_merge($data, [
                'status'   => 'pending', // Submit for approval after onboarding
                'owner_id' => $user->id,
            ]);
            $company = $this->companyRepository->create($companyData);
            
            // Associate user with the company
            $user->update(['company_id' => $company->id]);
            
            Log::info('Created new company during onboarding', [
                'user_id' => $user->id,
                'company_id' => $company->id,
                'status' => $company->status
            ]);
        } else {
            // Update existing company and ensure status is 'pending' for approval
            $updateData = array_merge($data, [
                'status' => 'pending', // Submit for approval after onboarding
            ]);
            $company->update($updateData);
            
            Log::info('Updated existing company during onboarding', [
                'company_id' => $company->id,
                'status' => $company->status
            ]);
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
