<?php

namespace App\Domain\Company\Repositories;

use App\Domain\Company\Models\Company;

class EloquentCompanyRepository implements CompanyRepositoryInterface
{
    /**
     * {@inheritdoc}
     */
    public function create(array $data): Company
    {
        return Company::create($data);
    }

    /**
     * {@inheritdoc}
     */
    public function update(Company $company, array $data): Company
    {
        $company->fill($data);
        $company->save();

        return $company->fresh() ?? $company;
    }
}
