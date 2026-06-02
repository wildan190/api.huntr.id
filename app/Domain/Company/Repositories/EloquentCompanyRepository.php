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

    /**
     * {@inheritdoc}
     */
    public function getPaginated(array $filters = [], int $perPage = 10): \Illuminate\Pagination\LengthAwarePaginator
    {
        $query = Company::with(['documents']);

        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('email', 'ilike', "%{$search}%");
            });
        }

        return $query->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->orderBy('id', 'desc')
            ->paginate($perPage);
    }

    /**
     * {@inheritdoc}
     */
    public function getStats(): array
    {
        return [
            'total'    => Company::count(),
            'pending'  => Company::where('status', 'pending')->count(),
            'approved' => Company::where('status', 'approved')->count(),
            'rejected' => Company::where('status', 'rejected')->count(),
        ];
    }
}
