<?php

namespace App\Domain\Receipt\Listeners;

use App\Domain\Receipt\Events\GoodsInspected;
use App\Domain\Communication\Notifications\DatabaseNotification;

class SendGoodsInspectedNotification
{
    public function handle(GoodsInspected $event): void
    {
        $receipt = $event->receipt;
        $deliveryOrder = $receipt->deliveryOrder;
        $po = $deliveryOrder->purchaseOrder;
        
        // Notify vendor company about inspection results
        $hasRejectedItems = $receipt->hasRejectedItems();
        
        $receipt->deliveryOrder->purchaseOrder->vendorCompany->notify(
            new DatabaseNotification(
                $hasRejectedItems 
                    ? 'Items Rejected During Inspection' 
                    : 'Goods Receipt Inspected',
                $hasRejectedItems
                    ? "Buyer has rejected {$receipt->getTotalRejectedQty()} items during inspection for PO {$po->po_number}. A return request has been created automatically."
                    : "All items have been accepted for PO {$po->po_number}. Goods receipt completed successfully.",
                $hasRejectedItems ? "/returns" : "/receipts/{$receipt->id}",
                null,
                [
                    'type' => $hasRejectedItems ? 'goods_receipt_rejected_items' : 'goods_receipt_inspected',
                    'receipt_id' => $receipt->id,
                    'po_id' => $po->id,
                    'po_number' => $po->po_number,
                    'has_rejected_items' => $hasRejectedItems,
                    'rejected_qty' => $receipt->getTotalRejectedQty(),
                ]
            )
        );
    }
}
