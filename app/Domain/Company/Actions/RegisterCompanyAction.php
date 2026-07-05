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
        private readonly \App\Domain\Access\Actions\AssignRoleAction $assignRoleAction,
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

        // Check if a company with the same name or tax_id already exists for this owner
        // to prevent duplicate submissions, but allow registering different companies.
        $existingCompany = Company::where('owner_id', $user->id)
            ->where(function($query) use ($data) {
                $query->where('name', $data['name']);
                if (!empty($data['tax_id'])) {
                    $query->orWhere('tax_id', $data['tax_id']);
                }
            })
            ->first();

        if ($existingCompany) {
            // Update existing company and ensure status is 'pending' for approval
            $updateData = array_merge($data, [
                'status' => 'pending', // Re-submit for approval
            ]);
            $existingCompany->update($updateData);
            $company = $existingCompany;
            
            Log::info('Updated existing company during registration', [
                'company_id' => $company->id,
                'status' => $company->status
            ]);
        } else {
            // Create a completely new company
            $companyData = array_merge($data, [
                'status'   => 'pending',
                'owner_id' => $user->id,
            ]);
            $company = $this->companyRepository->create($companyData);
            
            // NOTE: We do NOT update $user->company_id here.
            // A user can own multiple companies. $user->company_id should represent
            // their currently active or primary company context, which should be 
            // handled by a separate "switch company" action, not registration.
            
            Log::info('Created new company during registration', [
                'user_id' => $user->id,
                'company_id' => $company->id,
                'status' => $company->status
            ]);
        }

        // Ensure the company owner has manager role
        // This is important because the user who creates/owns the company should be able to manage it
        if (!$user->hasRole('manager')) {
            $this->assignRoleAction->execute($user, 'manager');
            Log::info('Assigned manager role to company owner', [
                'user_id' => $user->id,
                'company_id' => $company->id,
            ]);
        }

        // If user doesn't have an active company yet, make this their active company
        // This ensures the registerer has immediate full access to their company
        if (!$user->company_id) {
            $user->update(['company_id' => $company->id]);
            Log::info('Set registered company as user active company', [
                'user_id' => $user->id,
                'company_id' => $company->id,
            ]);
        }

        Log::info('Final company ID: ' . $company->id, [
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
