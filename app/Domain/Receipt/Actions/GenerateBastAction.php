<?php

namespace App\Domain\Receipt\Actions;

use App\Domain\Receipt\Models\Bast;
use App\Domain\Receipt\Models\GoodsReceipt;
use App\Domain\Order\Models\PurchaseOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class GenerateBastAction
{
    /**
     * Generate BAST automatically from goods receipt
     *
     * @param GoodsReceipt $goodsReceipt
     * @param array $data Additional BAST data (signatories, notes, etc.)
     * @return Bast
     */
    public function execute(GoodsReceipt $goodsReceipt, array $data = []): Bast
    {
        // Check if BAST already exists
        $existingBast = Bast::where('goods_receipt_id', $goodsReceipt->id)->first();
        if ($existingBast) {
            return $existingBast;
        }

        $po = $goodsReceipt->purchaseOrder;

        if (!$po) {
            throw ValidationException::withMessages([
                'goods_receipt' => ['Purchase order not found for this receipt.'],
            ]);
        }

        return DB::transaction(function () use ($goodsReceipt, $po, $data) {
            $bast = Bast::create([
                'goods_receipt_id' => $goodsReceipt->id,
                'po_id' => $po->id,
                'buyer_company_id' => $po->buyer_company_id,
                'vendor_company_id' => $po->vendor_company_id,
                'bast_number' => Bast::generateBastNumber(),
                'bast_date' => $data['bast_date'] ?? now()->date(),
                'status' => 'draft',
                'items' => $this->prepareItems($goodsReceipt, $po),
                'handover_notes' => $data['handover_notes'] ?? null,
                'witness_notes' => $data['witness_notes'] ?? null,
                'handed_by_user_id' => $data['handed_by_user_id'] ?? null,
                'handed_by_name' => $data['handed_by_name'] ?? null,
                'handed_by_position' => $data['handed_by_position'] ?? null,
                'received_by_user_id' => $data['received_by_user_id'] ?? null,
                'received_by_name' => $data['received_by_name'] ?? null,
                'received_by_position' => $data['received_by_position'] ?? null,
                'witness_user_id' => $data['witness_user_id'] ?? null,
                'witness_name' => $data['witness_name'] ?? null,
                'witness_position' => $data['witness_position'] ?? null,
                'created_by' => $data['created_by'] ?? null,
            ]);

            Log::info('BAST generated successfully', [
                'bast_id' => $bast->id,
                'bast_number' => $bast->bast_number,
                'po_id' => $po->id,
            ]);

            return $bast;
        });
    }

    /**
     * Prepare items from goods receipt for BAST
     */
    private function prepareItems(GoodsReceipt $goodsReceipt, PurchaseOrder $po): array
    {
        $items = [];

        if ($po->items) {
            foreach ($po->items as $poItem) {
                $items[] = [
                    'rfq_item_id' => $poItem['rfq_item_id'] ?? null,
                    'catalogue_id' => $poItem['catalogue_id'] ?? null,
                    'quantity_ordered' => $poItem['qty'] ?? 0,
                    'quantity_received' => $goodsReceipt->received_qty ?? 0,
                    'condition' => 'good',
                    'notes' => null,
                ];
            }
        }

        return $items;
    }
}
