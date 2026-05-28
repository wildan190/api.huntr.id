<?php

namespace App\Domain\Catalogue\Repositories;

use App\Domain\Catalogue\Models\Catalogue;

interface CatalogueRepositoryInterface
{
    /**
     * Bulk insert catalogue items for a company.
     *
     * @param array $items Array of attribute arrays to insert
     * @return int Number of rows inserted
     */
    public function bulkCreate(array $items): int;
}
