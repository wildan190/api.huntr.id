<?php

namespace App\Domain\Rfq\Repositories;

use App\Domain\Rfq\Models\Rfq;
use App\Domain\Rfq\Models\RfqItem;

class EloquentRfqRepository implements RfqRepositoryInterface
{
    /**
     * {@inheritdoc}
     */
    public function create(array $data): Rfq
    {
        return Rfq::create($data);
    }

    /**
     * {@inheritdoc}
     */
    public function createItems(array $items): void
    {
        foreach ($items as $item) {
            RfqItem::create($item);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function update(Rfq $rfq, array $data): Rfq
    {
        $rfq->update($data);

        return $rfq->fresh();
    }
}
