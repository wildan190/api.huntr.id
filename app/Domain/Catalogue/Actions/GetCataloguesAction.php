<?php

namespace App\Domain\Catalogue\Actions;

use App\Domain\Catalogue\Models\Catalogue;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Action to retrieve catalogue items with filtering and pagination.
 */
class GetCataloguesAction
{
    /**
     * Execute the action.
     *
     * @param array $params
     * @return LengthAwarePaginator
     */
    public function execute(array $params): LengthAwarePaginator
    {
        $query = Catalogue::query()->with('company');

        if (!empty($params['company_id'])) {
            $query->where('company_id', $params['company_id']);
        } else {
            // Global marketplace filtering
            $query->whereHas('company', function ($q) {
                $q->where('type', 'vendor')
                  ->whereIn('status', ['approved', 'pending']);
            });
        }

        if (!empty($params['search'])) {
            $search = $params['search'];
            $query->where(function($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('item_code', 'ilike', "%{$search}%")
                  ->orWhere('category', 'ilike', "%{$search}%");
            });
        }

        if (!empty($params['category'])) {
            $query->where('category', $params['category']);
        }

        return $query->orderBy('id', 'desc')->paginate($params['per_page'] ?? 20);
    }
}
