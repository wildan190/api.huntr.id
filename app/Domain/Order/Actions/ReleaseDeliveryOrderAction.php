<?php

namespace App\Domain\Order\Actions;

use App\Domain\Order\Repositories\OrderRepositoryInterface;
use App\Domain\Company\Models\Company;
use App\Domain\Order\Models\DeliveryOrder;
use App\Domain\Order\Models\PurchaseOrder;
use Illuminate\Validation\ValidationException;

class ReleaseDeliveryOrderAction
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository
    ) {}

    /**
     * Vendor arranges delivery, shipping items and releasing the Delivery Order.
     *
     * @param Company $vendorCompany Target vendor company
     * @param PurchaseOrder $po Target PO
     * @return DeliveryOrder
     * @throws ValidationException
     */
    public function execute(Company $vendorCompany, PurchaseOrder $po): DeliveryOrder
    {
        if ($po->vendor_id !== $vendorCompany->id) {
            throw ValidationException::withMessages([
                'vendor' => ['This PO does not belong to your company.'],
            ]);
        }

        if ($po->status !== 'paid') {
            throw ValidationException::withMessages([
                'po' => ['You cannot ship items before PO is paid.'],
            ]);
        }

        // 1. Move PO status to delivery
        $this->orderRepository->updatePurchaseOrder($po, ['status' => 'delivery']);

        // 2. Generate Delivery Order
        $doNumber = 'DO-' . date('Ymd') . '-' . str_pad($po->id, 4, '0', STR_PAD_LEFT);

        return $this->orderRepository->createDeliveryOrder([
            'purchase_order_id' => $po->id,
            'do_number'         => $doNumber,
            'status'            => 'shipped',
        ]);
    }
}
