<?php

namespace App\Domain\Rfq\Repositories;

use App\Domain\Rfq\Models\Rfq;
use App\Domain\Rfq\Models\RfqItem;
use Illuminate\Support\Facades\Log;

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
        // Debug: Log proses pembuatan RFQ items
        Log::info('DEBUG: EloquentRfqRepository - Creating RFQ items', [
            'total_items_to_create' => count($items),
            'items_details' => $items,
        ]);

        foreach ($items as $item) {
            RfqItem::create($item);
        }

        // Debug: Verify items created
        $createdCount = RfqItem::where('rfq_id', $items[0]['rfq_id'] ?? null)->count();
        Log::info('DEBUG: EloquentRfqRepository - Items creation completed', [
            'created_items_count' => $createdCount,
        ]);
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
