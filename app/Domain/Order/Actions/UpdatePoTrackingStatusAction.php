<?php

namespace App\Domain\Order\Actions;

use App\Domain\Company\Models\Company;
use App\Domain\Order\Models\PurchaseOrder;
use App\Domain\Communication\Actions\BroadcastWebsocketNotificationAction;
use Illuminate\Validation\ValidationException;

/**
 * Allows a vendor to advance the tracking status of a PO through the delivery lifecycle.
 *
 * Allowed transitions (in order):
 *   confirmed → packing → in_transit (via arrangeDelivery) → delivered
 */
class UpdatePoTrackingStatusAction
{
    /**
     * Ordered list of states that vendor can manually advance through.
     * Note: 'paid' → 'packing' is triggered by vendor; 'in_transit' is set via Arrange Delivery.
     */
    private const ALLOWED_TRANSITIONS = [
        'confirmed' => 'packing',
        'paid' => 'packing',
        'packing' => 'in_transit',
        'in_transit' => 'delivered',
    ];

    private const STATUS_LABELS = [
        'issued' => 'PO Issued',
        'published' => 'PO Issued',
        'confirmed' => 'PO Confirmed',
        'paid' => 'Payment Received',
        'packing' => 'Goods Being Packed',
        'in_transit' => 'Goods In Transit',
        'delivered' => 'Goods Delivered',
        'completed' => 'Order Completed',
        'done' => 'Order Completed',
    ];

    public function __construct(
        private readonly BroadcastWebsocketNotificationAction $broadcastAction
    ) {
    }

    /**
     * @param Company $vendorCompany  The acting vendor company
     * @param PurchaseOrder $po       Target Purchase Order
     * @param string $newStatus       The target status to advance to
     * @param string|null $note       Optional note for this status update
     * @return PurchaseOrder          Updated PO
     * @throws ValidationException
     */
    public function execute(
        Company $vendorCompany,
        PurchaseOrder $po,
        string $newStatus,
        ?string $note = null
    ): PurchaseOrder {

        if ($po->vendor_id !== $vendorCompany->id) {
            throw ValidationException::withMessages([
                'vendor' => ['This PO does not belong to your company.'],
            ]);
        }

        $currentStatus = $po->status;

        $allowedNext = self::ALLOWED_TRANSITIONS[$currentStatus] ?? null;
        if ($allowedNext !== $newStatus) {
            throw ValidationException::withMessages([
                'status' => ["Cannot transition from '{$currentStatus}' to '{$newStatus}'. Expected: '{$allowedNext}'."],
            ]);
        }

        $timeline = $po->tracking_timeline ?? [];
        $timeline[] = [
            'status' => $newStatus,
            'label' => self::STATUS_LABELS[$newStatus] ?? ucfirst($newStatus),
            'timestamp' => now()->toIso8601String(),
            'actor_name' => $vendorCompany->name,
            'actor_type' => 'vendor',
            'note' => $note,
        ];

        $po->update([
            'status' => $newStatus,
            'tracking_timeline' => $timeline,
        ]);

        $statusLabel = self::STATUS_LABELS[$newStatus] ?? ucfirst($newStatus);
        $this->broadcastAction->execute(
            "Order Update: {$statusLabel}",
            "Vendor has updated PO {$po->po_number}: {$statusLabel}.",
            'test-channel',
            true,
            $po->created_by,
            "/orders?search={$po->po_number}"
        );

        $po->refresh();

        return $po;
    }
}
