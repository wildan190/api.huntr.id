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

        // Check if a company with the same tax_id AND same type already exists.
        // Business rule: 1 tax_id can register as 1 Vendor AND 1 Buyer (two separate workspaces),
        // but NOT as 2 Vendors or 2 Buyers.
        if (!empty($data['tax_id']) && !empty($data['type'])) {
            $typeDuplicate = Company::where('tax_id', $data['tax_id'])
                ->where('type', $data['type'])
                ->first();

            if ($typeDuplicate) {
                $typeLabel = $data['type'] === 'vendor' ? 'Vendor' : 'Buyer';
                throw new \Illuminate\Validation\ValidationException(
                    \Illuminate\Support\Facades\Validator::make([], []),
                    response()->json([
                        'message' => "NPWP/Tax ID ini sudah terdaftar sebagai perusahaan {$typeLabel}. " .
                                     "Anda tidak dapat mendaftarkan akun {$typeLabel} baru dengan NPWP yang sama.",
                        'errors' => [
                            'tax_id' => ["NPWP sudah terdaftar sebagai {$typeLabel}"]
                        ]
                    ], 422)
                );
            }
        }

        // Check for re-submission of the very same company (same owner + same name + same type)
        // to prevent accidental duplicates within the same workspace registration.
        $existingCompany = Company::where('owner_id', $user->id)
            ->where('name', $data['name'])
            ->where('type', $data['type'])
            ->first();

        if ($existingCompany) {
            // Re-submission: update and re-submit for approval
            $updateData = array_merge($data, [
                'status' => 'pending',
            ]);
            $existingCompany->update($updateData);
            $company = $existingCompany;

            Log::info('Updated existing company during registration', [
                'company_id' => $company->id,
                'status'     => $company->status
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
