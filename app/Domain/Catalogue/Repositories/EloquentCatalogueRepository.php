<?php

namespace App\Domain\Catalogue\Repositories;

use App\Domain\Catalogue\Models\Catalogue;

class EloquentCatalogueRepository implements CatalogueRepositoryInterface
{
    /**
     * {@inheritdoc}
     */
    public function bulkCreate(array $items): int
    {
        $count = 0;

        foreach ($items as $item) {
            Catalogue::create($item);
            $count++;
        }

        return $count;
    }
}
