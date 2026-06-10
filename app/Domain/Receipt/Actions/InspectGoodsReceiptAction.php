<?php

namespace App\Domain\Receipt\Actions;

use App\Domain\Receipt\Models\GoodsReceipt;
use App\Domain\Order\Models\GoodsReturn;
use App\Domain\Receipt\Events\GoodsInspected;
use App\Domain\Order\Events\ReturnCreated;
use Illuminate\Support\Facades\DB;

class InspectGoodsReceiptAction
{
    public function execute(GoodsReceipt $receipt, array $data): GoodsReceipt
    {
        return DB::transaction(function () use ($receipt, $data) {
            // Update goods receipt with inspection data
            $receipt->update([
                'items_inspection' => $data['items_inspection'],
                'accepted_items' => $data['accepted_items'] ?? [],
                'rejected_items' => $data['rejected_items'] ?? [],
                'inspection_notes' => $data['inspection_notes'] ?? null,
                'inspection_status' => 'completed',
                'inspected_at' => now(),
                'inspected_by' => auth()->id(),
                'status' => 'completed',
            ]);

            // Trigger event for inspection completed
            event(new GoodsInspected($receipt));

            // If there are rejected items, automatically create return
            if (!empty($data['rejected_items']) && count($data['rejected_items']) > 0) {
                $this->createAutomaticReturn($receipt, $data['rejected_items'], $data);
            }

            return $receipt->fresh();
        });
    }

    protected function createAutomaticReturn(GoodsReceipt $receipt, array $rejectedItems, array $inspectionData): GoodsReturn
    {
        $deliveryOrder = $receipt->deliveryOrder;
        $po = $deliveryOrder->purchaseOrder;

        // Calculate total return value
        $totalValue = 0;
        foreach ($rejectedItems as $item) {
            $totalValue += ($item['rejected_qty'] ?? 0) * ($item['unit_price'] ?? 0);
        }

        // Create return automatically
        $return = GoodsReturn::create([
            'goods_receipt_id' => $receipt->id,
            'po_id' => $po->id,
            'buyer_company_id' => $po->buyer_company_id,
            'vendor_company_id' => $po->vendor_company_id,
            'return_number' => GoodsReturn::generateReturnNumber(),
            'return_date' => now(),
            'status' => 'pending_resolution',
            'return_reason' => 'quality_issue',
            'return_description' => $inspectionData['inspection_notes'] ?? 'Items rejected during goods receipt inspection',
            'items' => $rejectedItems,
            'total_return_value' => $totalValue,
            'inspection_status' => 'completed',
            'inspection_notes' => 'Auto-created from goods receipt inspection',
            'resolution_status' => 'pending_vendor',
            'created_by' => auth()->id(),
        ]);

        // Trigger return created event (will send notifications)
        event(new ReturnCreated($return));

        return $return;
    }
}
