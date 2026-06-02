<?php

namespace App\Domain\Company\Actions;

use App\Domain\Company\Repositories\CompanyRepositoryInterface;
use App\Domain\Company\Models\Company;

class AuditCompanyAction
{
    public function __construct(
        private readonly CompanyRepositoryInterface $companyRepository
    ) {}

    /**
     * Admin approve or decline a company registration request.
     *
     * @param Company $company The target company
     * @param string $action 'approve' or 'decline'
     * @param string|null $notes Audit reason notes
     * @return Company
     */
    public function execute(Company $company, string $action, ?string $notes = null): Company
    {
        $status = $action === 'approve' ? 'approved' : 'rejected';

        return $this->companyRepository->update($company, [
            'status'             => $status,
            'verification_notes' => $notes,
        ]);
    }
}
