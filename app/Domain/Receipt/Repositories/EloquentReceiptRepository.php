<?php

namespace App\Domain\Receipt\Repositories;

use App\Domain\Receipt\Models\GoodsReceipt;
use App\Domain\Order\Models\Invoice;

class EloquentReceiptRepository implements ReceiptRepositoryInterface
{
    /**
     * {@inheritdoc}
     */
    public function createGoodsReceipt(array $data): GoodsReceipt
    {
        return GoodsReceipt::create($data);
    }

    /**
     * {@inheritdoc}
     */
    public function updateInvoice(Invoice $invoice, array $data): Invoice
    {
        $invoice->update($data);

        return $invoice->fresh();
    }
}
