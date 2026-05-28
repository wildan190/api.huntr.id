<?php

namespace App\Domain\Order\Repositories;

use App\Domain\Order\Models\PurchaseOrder;
use App\Domain\Order\Models\Invoice;
use App\Domain\Order\Models\DeliveryOrder;
use App\Domain\Proposal\Models\Proposal;

class EloquentOrderRepository implements OrderRepositoryInterface
{
    /**
     * {@inheritdoc}
     */
    public function createPurchaseOrder(array $data): PurchaseOrder
    {
        return PurchaseOrder::create($data);
    }

    /**
     * {@inheritdoc}
     */
    public function updatePurchaseOrder(PurchaseOrder $po, array $data): PurchaseOrder
    {
        $po->update($data);

        return $po->fresh();
    }

    /**
     * {@inheritdoc}
     */
    public function createInvoice(array $data): Invoice
    {
        return Invoice::create($data);
    }

    /**
     * {@inheritdoc}
     */
    public function updateInvoice(Invoice $invoice, array $data): Invoice
    {
        $invoice->update($data);

        return $invoice->fresh();
    }

    /**
     * {@inheritdoc}
     */
    public function findUnpaidProformaInvoice(PurchaseOrder $po): ?Invoice
    {
        return $po->invoices()
            ->where('type', 'proforma')
            ->where('status', 'unpaid')
            ->first();
    }

    /**
     * {@inheritdoc}
     */
    public function findAcceptedProposal(PurchaseOrder $po): ?Proposal
    {
        return $po->rfq->proposals()->where('status', 'accepted')->first();
    }

    /**
     * {@inheritdoc}
     */
    public function createDeliveryOrder(array $data): DeliveryOrder
    {
        return DeliveryOrder::create($data);
    }

    /**
     * {@inheritdoc}
     */
    public function updateDeliveryOrder(DeliveryOrder $do, array $data): DeliveryOrder
    {
        $do->update($data);

        return $do->fresh();
    }
}
