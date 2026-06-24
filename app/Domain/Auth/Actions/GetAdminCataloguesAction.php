<?php

namespace App\Domain\Auth\Actions;

use App\Domain\Catalogue\Models\Catalogue;
use Illuminate\Pagination\LengthAwarePaginator;

class GetAdminCataloguesAction
{
    public function execute(array $filters = [], int $perPage = 10): LengthAwarePaginator
    {
        $query = Catalogue::with('company')->orderBy('created_at', 'desc');

        if (!empty($filters['search'])) {
            $search = $filters['search'];

            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('item_code', 'ilike', "%{$search}%")
                  ->orWhereHas('company', function ($c) use ($search) {
                      $c->where('name', 'ilike', "%{$search}%");
                  });
            });
        }

        return $query->paginate($perPage);
    }
}
