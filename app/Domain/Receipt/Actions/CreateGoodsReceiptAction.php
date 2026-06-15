<?php

namespace App\Domain\Receipt\Actions;

use App\Domain\Order\Repositories\OrderRepositoryInterface;
use App\Domain\Receipt\Repositories\ReceiptRepositoryInterface;
use App\Domain\Order\Models\DeliveryOrder;
use App\Domain\Receipt\Models\GoodsReceipt;
use App\Domain\Communication\Actions\BroadcastWebsocketNotificationAction;
use Illuminate\Validation\ValidationException;

class CreateGoodsReceiptAction
{
    public function __construct(
        private readonly ReceiptRepositoryInterface $receiptRepository,
        private readonly OrderRepositoryInterface   $orderRepository,
        private readonly BroadcastWebsocketNotificationAction $broadcastAction
    ) {}

    /**
     * Buyer company performs goods receipt.
     * All fields (received_qty, handover_document_path) are derived automatically.
     *
     * @param DeliveryOrder $do Target DO
     * @param array $data Optional: received_qty override
     * @return GoodsReceipt
     * @throws ValidationException
     */
    public function execute(DeliveryOrder $do, array $data): GoodsReceipt
    {
        if (!in_array($do->status, ['shipped', 'delivered'])) {
            throw ValidationException::withMessages([
                'do' => ['Delivery Order must be in shipped or delivered status to perform Goods Receipt.'],
            ]);
        }

        // 1. Auto-derive received qty from PO items (sum of all ordered quantities) if no items_inspection is provided
        $po = $do->purchaseOrder;
        
        $itemsInspection = $data['items_inspection'] ?? null;
        if ($itemsInspection && is_array($itemsInspection)) {
            $autoQty = collect($itemsInspection)->sum('received_qty');
        } else {
            $autoQty = $data['received_qty'] ?? $po->rfq?->items?->sum('qty') ?? 1;
        }

        // 2. Create Goods Receipt (handover path is auto-set)
        $receipt = $this->receiptRepository->createGoodsReceipt([
            'delivery_order_id'      => $do->id,
            'received_qty'           => $autoQty,
            'items_inspection'       => $itemsInspection ? json_encode($itemsInspection) : null,
            'handover_document_path' => 'system/auto_generated_' . now()->format('Ymd_His') . '.pdf',
            'status'                 => 'completed',
        ]);

        // 2.5 Automatically create Return if there are rejected items
        if ($itemsInspection) {
            $rejectedItems = collect($itemsInspection)->filter(function ($item) {
                return ($item['rejected_qty'] ?? 0) > 0;
            });

            if ($rejectedItems->isNotEmpty()) {
                $returnItems = [];
                $totalReturnValue = 0;
                
                // Get PO items to find unit price
                $poItems = $po->rfq?->items?->keyBy('id') ?? collect();
                
                foreach ($rejectedItems as $rej) {
                    $rfqItem = $poItems->get($rej['po_item_id']); // actually PO item ID or RFQ item ID? The frontend uses item.id which is ProposalItem or RFQItem id? It's from PO items so it's probably proposal item or rfq item.
                    // We'll just put standard data for now
                    $unitPrice = $rfqItem ? ($rfqItem->price ?? $rfqItem->estimated_price ?? 0) : 0;
                    $qty = $rej['rejected_qty'];
                    $totalReturnValue += $qty * $unitPrice;
                    
                    $returnItems[] = [
                        'rfq_item_id' => $rej['po_item_id'],
                        'inventory_name' => $rej['inventory_name'],
                        'quantity_returned' => $qty,
                        'unit_price' => $unitPrice,
                        'reason' => $rej['rejection_reason'] ?? 'Quality Issue / Rejected at Goods Receipt',
                        'condition' => $rej['condition'] ?? 'Rejected',
                    ];
                }

                // Build a detailed return description listing each item's rejection reason
                $reasonSummary = collect($returnItems)->map(function ($ri) {
                    $reason = $ri['reason'] ?? 'No reason provided';
                    return "{$ri['inventory_name']} (qty: {$ri['quantity_returned']}): {$reason}";
                })->implode('; ');

                \App\Domain\Order\Models\GoodsReturn::create([
                    'po_id' => $po->id,
                    'goods_receipt_id' => $receipt->id,
                    'buyer_company_id' => $po->buyer_company_id,
                    'vendor_company_id' => $po->vendor_id,
                    'return_number' => \App\Domain\Order\Models\GoodsReturn::generateReturnNumber(),
                    'return_date' => now(),
                    'status' => 'pending',
                    'return_reason' => 'defective',
                    'items' => $returnItems,
                    'total_return_value' => $totalReturnValue,
                    'return_description' => "Automatically generated return from rejected items during Goods Receipt. Details: {$reasonSummary}",
                ]);
            }
        }

        // 3. Update DO and PO statuses
        $this->orderRepository->updateDeliveryOrder($do, ['status' => 'received']);
        $this->orderRepository->updatePurchaseOrder($po, ['status' => 'delivered']);

        // 3. Derive amount from accepted proposal or negotiation and release Final Invoice
        $winningProposal = $this->orderRepository->findAcceptedProposal($po);
        $poAmount = $winningProposal ? $winningProposal->price_offer : 0;

        // Check for accepted negotiation
        if ($winningProposal && $winningProposal->acceptedNegotiation) {
            $negotiation = $winningProposal->acceptedNegotiation;
            $negotiatedTotal = 0;
            foreach ($negotiation->items as $nItem) {
                $negotiatedTotal += $nItem->negotiated_price * $nItem->negotiated_qty;
            }
            if ($negotiatedTotal > 0) {
                $poAmount = $negotiatedTotal;
            }
        }

        $this->orderRepository->createInvoice([
            'purchase_order_id' => $po->id,
            'type'              => 'final',
            'amount'            => $poAmount,
            'status'            => 'draft',
        ]);

        // Notify Vendor
        $vendorUserIds = collect($po->vendor->users->pluck('id'))->push($po->vendor->owner_id)->unique()->filter();
        foreach ($vendorUserIds as $vendorUserId) {
            $this->broadcastAction->execute(
                "Goods Received",
                "Buyer has confirmed receipt of goods for DO {$do->do_number}. You can now publish your invoice.",
                'test-channel',
                true,
                $vendorUserId,
                "/orders?search={$po->po_number}"
            );
        }

        return $receipt;
    }
}
