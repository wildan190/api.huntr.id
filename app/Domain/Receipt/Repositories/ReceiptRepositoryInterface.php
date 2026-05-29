<?php

namespace App\Domain\Receipt\Repositories;

use App\Domain\Receipt\Models\GoodsReceipt;
use App\Domain\Order\Models\Invoice;

interface ReceiptRepositoryInterface
{
    /**
     * Create a new Goods Receipt record.
     *
     * @param array $data
     * @return GoodsReceipt
     */
    public function createGoodsReceipt(array $data): GoodsReceipt;

    /**
     * Update an Invoice's attributes.
     *
     * @param Invoice $invoice
     * @param array $data
     * @return Invoice
     */
    public function updateInvoice(Invoice $invoice, array $data): Invoice;
}
