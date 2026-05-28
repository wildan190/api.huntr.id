<?php

namespace App\Domain\Order\Actions;

use App\Domain\Order\Repositories\OrderRepositoryInterface;
use App\Domain\Company\Models\Company;
use App\Domain\Order\Models\PurchaseOrder;
use Illuminate\Validation\ValidationException;

class ConfirmPurchaseOrderAction
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository
    ) {}

    /**
     * Vendor confirms the generated Purchase Order, releasing the Proforma Invoice.
     *
     * @param Company $vendorCompany Target vendor company
     * @param PurchaseOrder $po Target PO
     * @return PurchaseOrder
     * @throws ValidationException
     */
    public function execute(Company $vendorCompany, PurchaseOrder $po): PurchaseOrder
    {
        if ($po->vendor_id !== $vendorCompany->id) {
            throw ValidationException::withMessages([
                'vendor' => ['This PO does not belong to your company.'],
            ]);
        }

        // 1. Move PO status to confirmed
        $po = $this->orderRepository->updatePurchaseOrder($po, ['status' => 'confirmed']);

        // 2. Derive PO amount from the accepted proposal
        $winningProposal = $this->orderRepository->findAcceptedProposal($po);
        $poAmount        = $winningProposal ? $winningProposal->price_offer : 0;

        // 3. Release Proforma Invoice
        $this->orderRepository->createInvoice([
            'purchase_order_id' => $po->id,
            'type'              => 'proforma',
            'amount'            => $poAmount,
            'status'            => 'unpaid',
        ]);

        return $po;
    }
}
