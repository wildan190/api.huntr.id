<?php

namespace App\Domain\Order\Actions;

use App\Domain\Order\Repositories\OrderRepositoryInterface;
use App\Domain\Company\Models\Company;
use App\Domain\Order\Models\DeliveryOrder;
use App\Domain\Order\Models\PurchaseOrder;
use App\Domain\Communication\Actions\BroadcastWebsocketNotificationAction;
use Illuminate\Validation\ValidationException;

class ReleaseDeliveryOrderAction
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly BroadcastWebsocketNotificationAction $broadcastAction
    ) {}

    /**
     * Vendor arranges delivery, shipping items and releasing the Delivery Order.
     *
     * @param Company $vendorCompany Target vendor company
     * @param PurchaseOrder $po Target PO
     * @param string|null $trackingNumber Courier tracking number
     * @return DeliveryOrder
     * @throws ValidationException
     */
    public function execute(Company $vendorCompany, PurchaseOrder $po, ?string $trackingNumber = null): DeliveryOrder
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
        $buyerAddress = $po->buyer?->address;
        if ($po->buyer) {
            $buyerAddress = collect(array_filter([
                $po->buyer->address,
                $po->buyer->city,
                $po->buyer->regency,
                $po->buyer->provincy_country,
                $po->buyer->zip_code,
            ]))->implode(', ');
        }

        $do = $this->orderRepository->createDeliveryOrder([
            'purchase_order_id' => $po->id,
            'do_number'         => $doNumber,
            'tracking_number'   => $trackingNumber,
            'delivery_address'  => $buyerAddress,
            'status'            => 'shipped',
        ]);

        $this->broadcastAction->execute(
            "Delivery Arranged",
            "Vendor has shipped items for PO {$po->po_number}. Delivery Order {$do->do_number} has been released.",
            'test-channel',
            true,
            $po->created_by,
            "/orders?search={$po->po_number}"
        );

        return $do;
    }
}
