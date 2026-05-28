<?php

namespace App\Domain\Company\Actions;

use App\Domain\Company\Repositories\CompanyRepositoryInterface;
use App\Domain\Company\Models\Company;
use Illuminate\Support\Facades\Log;

class UpdateCompanyAction
{
    public function __construct(
        private readonly CompanyRepositoryInterface $companyRepository
    ) {}

    /**
     * Update company details.
     *
     * @param Company $company
     * @param array $data
     * @return Company
     */
    public function execute(Company $company, array $data): Company
    {
        Log::info('UpdateCompanyAction executing for company ID: ' . $company->id, [
            'data' => $data
        ]);

        return $this->companyRepository->update($company, $data);
    }
}
