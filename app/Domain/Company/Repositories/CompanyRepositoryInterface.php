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

    /**
     * Get companies with pagination and filters.
     *
     * @param array $filters
     * @param int $perPage
     * @return \Illuminate\Pagination\LengthAwarePaginator
     */
    public function getPaginated(array $filters = [], int $perPage = 10): \Illuminate\Pagination\LengthAwarePaginator;

    /**
     * Get company statistics by status.
     *
     * @return array
     */
    public function getStats(): array;
}
