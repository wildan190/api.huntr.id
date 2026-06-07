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

        // 1. Auto-derive received qty from PO items (sum of all ordered quantities)
        $po = $do->purchaseOrder;
        $autoQty = $data['received_qty'] ?? $po->rfq?->items?->sum('qty') ?? 1;

        // 2. Create Goods Receipt (handover path is auto-set)
        $receipt = $this->receiptRepository->createGoodsReceipt([
            'delivery_order_id'      => $do->id,
            'received_qty'           => $autoQty,
            'handover_document_path' => 'system/auto_generated_' . now()->format('Ymd_His') . '.pdf',
            'status'                 => 'completed',
        ]);

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
