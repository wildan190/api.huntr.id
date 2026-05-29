<?php

namespace App\Domain\Company\Repositories;

use App\Domain\Company\Models\Company;

interface CompanyRepositoryInterface
{
    /**
     * Create a new company record.
     *
     * @param array $data
     * @return Company
     */
    public function create(array $data): Company;

    /**
     * Update company attributes.
     *
     * @param Company $company
     * @param array $data
     * @return Company
     */
    public function update(Company $company, array $data): Company;
}
