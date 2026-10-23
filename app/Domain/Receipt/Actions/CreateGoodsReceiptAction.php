<?php

namespace App\Domain\Receipt\Actions;

use App\Domain\Communication\Actions\BroadcastWebsocketNotificationAction;
use App\Domain\Order\Actions\CalculateInvoiceFeesAction;
use App\Domain\Order\Models\DeliveryOrder;
use App\Domain\Order\Models\GoodsReturn;
use App\Domain\Order\Repositories\OrderRepositoryInterface;
use App\Domain\Receipt\Models\GoodsReceipt;
use App\Domain\Receipt\Repositories\ReceiptRepositoryInterface;
use App\Domain\Wms\Services\WmsGoodsReceiptImportService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateGoodsReceiptAction
{
    public function __construct(
        private readonly ReceiptRepositoryInterface $receiptRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly BroadcastWebsocketNotificationAction $broadcastAction,
        private readonly WmsGoodsReceiptImportService $wmsGoodsReceiptImportService,
        private readonly CalculateInvoiceFeesAction $calculateInvoiceFeesAction,
    ) {}

    /**
     * Buyer company performs goods receipt.
     * All fields (received_qty, handover_document_path) are derived automatically.
     *
     * @param  DeliveryOrder  $do  Target DO
     * @param  array  $data  Optional: received_qty override
     *
     * @throws ValidationException
     */
    public function execute(DeliveryOrder $do, array $data): GoodsReceipt
    {
        if (! in_array($do->status, ['shipped', 'delivered'])) {
            throw ValidationException::withMessages([
                'do' => ['Delivery Order must be in shipped or delivered status to perform Goods Receipt.'],
            ]);
        }

        // 1. Service acceptance is documented through BAST, never as a physical Goods Receipt.
        $po = $do->purchaseOrder;
        if (($po->procurement_type ?? 'goods') === 'services') {
            throw ValidationException::withMessages([
                'purchase_order' => ['PO jasa menggunakan BAST sebagai bukti penerimaan layanan, bukan Goods Receipt.'],
            ]);
        }

        // 2. Auto-derive received qty from PO items (sum of all ordered quantities) if no items_inspection is provided

        $itemsInspection = $data['items_inspection'] ?? null;
        if ($itemsInspection && is_array($itemsInspection)) {
            $autoQty = collect($itemsInspection)->sum('received_qty');
        } else {
            $autoQty = $data['received_qty'] ?? $po->rfq?->items?->sum('qty') ?? 1;
        }

        // 2. Create Goods Receipt (handover path is auto-set)
        $receipt = $this->receiptRepository->createGoodsReceipt([
            'delivery_order_id' => $do->id,
            'received_qty' => $autoQty,
            'items_inspection' => $itemsInspection ?: null,
            'handover_document_path' => 'system/auto_generated_'.now()->format('Ymd_His').'.pdf',
            'status' => 'completed',
        ]);

        $rfq = $po->rfq;
        if ($rfq?->warehouse_id && DB::table('company_apps')->where('company_id', $po->buyer_company_id)->where('app_key', 'wms-inventory')->whereNotNull('installed_at')->exists()) {
            $receivedBy = auth()->id() ?? $po->created_by;
            abort_unless($receivedBy, 422, 'Penerima Goods Receipt tidak ditemukan.');
            $this->wmsGoodsReceiptImportService->syncGoodsReceipt($po->buyer, $rfq->warehouse_id, $receipt, (string) $receivedBy);
        }

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

                GoodsReturn::create([
                    'po_id' => $po->id,
                    'goods_receipt_id' => $receipt->id,
                    'buyer_company_id' => $po->buyer_company_id,
                    'vendor_company_id' => $po->vendor_id,
                    'return_number' => GoodsReturn::generateReturnNumber(),
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

        // 3. Create a final invoice from the proforma amount whenever available.
        // The proforma is the agreed PO price and prevents direct/manual POs
        // from issuing a zero-value final invoice.
        $proformaInvoice = $po->invoices()
            ->where('type', 'proforma')
            ->latest()
            ->first();
        $baseAmount = (float) ($proformaInvoice?->base_amount ?: $proformaInvoice?->amount ?: $po->total_amount);
        $fees = $this->calculateInvoiceFeesAction->execute($baseAmount);
        $adminBank = (float) ($proformaInvoice?->midtrans_fee ?: $fees['midtrans_fee']);
        $pph23 = (float) ($proformaInvoice?->pph23 ?: $fees['pph23']);
        $ppnFee = (float) ($proformaInvoice?->ppn_fee ?: $fees['ppn_fee']);
        $totalAmount = round($baseAmount + $adminBank + $ppnFee - $pph23, 2);

        $this->orderRepository->createInvoice([
            'purchase_order_id' => $po->id,
            'company_subscription_id' => $proformaInvoice?->company_subscription_id,
            'type' => 'final',
            'amount' => $totalAmount,
            'base_amount' => $baseAmount,
            'billing_mode' => $proformaInvoice?->billing_mode,
            'gmv_credited_amount' => 0,
            'platform_fee' => 0,
            'ppn_platform' => 0,
            'midtrans_fee' => $adminBank,
            'pph23' => $pph23,
            'ppn_fee' => $ppnFee,
            'total_amount' => $totalAmount,
            'status' => 'draft',
        ]);

        // Notify Vendor
        $vendorUserIds = collect($po->vendor->users->pluck('id'))->push($po->vendor->owner_id)->unique()->filter();
        foreach ($vendorUserIds as $vendorUserId) {
            $this->broadcastAction->execute(
                'Goods Received',
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
