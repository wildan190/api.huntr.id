<?php

namespace App\Domain\Rfq\Repositories;

use App\Domain\Rfq\Models\Rfq;

interface RfqRepositoryInterface
{
    /**
     * Create a new RFQ record.
     *
     * @param array $data
     * @return Rfq
     */
    public function create(array $data): Rfq;

    /**
     * Create RFQ line items (bulk insert).
     *
     * @param array $items
     * @return void
     */
    public function createItems(array $items): void;

    /**
     * Update RFQ attributes.
     *
     * @param Rfq $rfq
     * @param array $data
     * @return Rfq
     */
    public function update(Rfq $rfq, array $data): Rfq;
}
