<?php

namespace App\Domain\Order\Repositories;

use App\Domain\Order\Models\PurchaseOrder;
use App\Domain\Order\Models\Invoice;
use App\Domain\Order\Models\DeliveryOrder;
use App\Domain\Proposal\Models\Proposal;

interface OrderRepositoryInterface
{
    /**
     * Create a new Purchase Order record.
     *
     * @param array $data
     * @return PurchaseOrder
     */
    public function createPurchaseOrder(array $data): PurchaseOrder;

    /**
     * Update a Purchase Order's attributes.
     *
     * @param PurchaseOrder $po
     * @param array $data
     * @return PurchaseOrder
     */
    public function updatePurchaseOrder(PurchaseOrder $po, array $data): PurchaseOrder;

    /**
     * Create a new Invoice record.
     *
     * @param array $data
     * @return Invoice
     */
    public function createInvoice(array $data): Invoice;

    /**
     * Update an Invoice's attributes.
     *
     * @param Invoice $invoice
     * @param array $data
     * @return Invoice
     */
    public function updateInvoice(Invoice $invoice, array $data): Invoice;

    /**
     * Find the unpaid proforma invoice for a PO.
     *
     * @param PurchaseOrder $po
     * @return Invoice|null
     */
    public function findUnpaidProformaInvoice(PurchaseOrder $po): ?Invoice;

    /**
     * Find the accepted (winning) proposal for a PO.
     *
     * @param PurchaseOrder $po
     * @return Proposal|null
     */
    public function findAcceptedProposal(PurchaseOrder $po): ?Proposal;

    /**
     * Create a new Delivery Order record.
     *
     * @param array $data
     * @return DeliveryOrder
     */
    public function createDeliveryOrder(array $data): DeliveryOrder;

    /**
     * Update a Delivery Order's attributes.
     *
     * @param DeliveryOrder $do
     * @param array $data
     * @return DeliveryOrder
     */
    public function updateDeliveryOrder(DeliveryOrder $do, array $data): DeliveryOrder;
}
