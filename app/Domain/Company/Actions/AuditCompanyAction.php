<?php

namespace App\Domain\Company\Actions;

use App\Domain\Company\Repositories\CompanyRepositoryInterface;
use App\Domain\Company\Models\Company;
use App\Domain\Auth\Models\User;
use Illuminate\Validation\UnauthorizedException;

class AuditCompanyAction
{
    public function __construct(
        private readonly CompanyRepositoryInterface $companyRepository
    ) {}

    /**
     * Admin approve or decline a company registration request.
     *
     * @param User $admin The auditing admin user
     * @param Company $company The target company
     * @param string $action 'approve' or 'decline'
     * @param string|null $notes Audit reason notes
     * @return Company
     * @throws UnauthorizedException
     */
    public function execute(User $admin, Company $company, string $action, ?string $notes = null): Company
    {
        if ($admin->role !== 'admin') {
            throw new UnauthorizedException("Only system administrators can audit company registrations.");
        }

        $status = $action === 'approve' ? 'approved' : 'rejected';

        return $this->companyRepository->update($company, [
            'status'             => $status,
            'verification_notes' => $notes,
        ]);
    }
}
