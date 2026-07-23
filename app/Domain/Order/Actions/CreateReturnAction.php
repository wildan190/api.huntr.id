<?php

namespace App\Domain\Order\Actions;

use App\Domain\Order\Models\GoodsReturn;
use App\Domain\Order\Models\PurchaseOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class CreateReturnAction
{
    /**
     * Create a new return for rejected goods
     *
     * @param PurchaseOrder $po
     * @param array $data Return details (reason, items, etc.)
     * @return GoodsReturn
     */
    public function execute(PurchaseOrder $po, array $data): GoodsReturn
    {

        if (empty($data['items'])) {
            throw ValidationException::withMessages([
                'items' => ['At least one return item is required.'],
            ]);
        }

        if (empty($data['return_reason'])) {
            throw ValidationException::withMessages([
                'return_reason' => ['Return reason is required.'],
            ]);
        }

        return DB::transaction(function () use ($po, $data) {
            $return = GoodsReturn::create([
                'po_id' => $po->id,
                'goods_receipt_id' => $data['goods_receipt_id'] ?? null,
                'bast_id' => $data['bast_id'] ?? null,
                'buyer_company_id' => $po->buyer_company_id,
                'vendor_company_id' => $po->vendor_company_id,
                'return_number' => GoodsReturn::generateReturnNumber(),
                'return_date' => $data['return_date'] ?? now()->date(),
                'status' => $data['status'] ?? 'pending',
                'return_reason' => $data['return_reason'],
                'items' => $data['items'],
                'total_return_value' => $this->calculateTotalValue($data['items']),
                'return_description' => $data['return_description'] ?? null,
                'photos' => $data['photos'] ?? null,
                'return_address' => $data['return_address'] ?? null,
                'courier_name' => $data['courier_name'] ?? null,
                'tracking_number' => $data['tracking_number'] ?? null,
                'created_by' => $data['created_by'] ?? null,
            ]);

            Log::info('Return created successfully', [
                'return_id' => $return->id,
                'return_number' => $return->return_number,
                'po_id' => $po->id,
                'return_reason' => $return->return_reason,
                'total_value' => $return->total_return_value,
            ]);

            return $return;
        });
    }

    /**
     * Calculate total return value from items
     */
    private function calculateTotalValue(array $items): float
    {
        $total = 0;

        foreach ($items as $item) {
            $itemTotal = ($item['quantity_returned'] ?? 0) * ($item['unit_price'] ?? 0);
            $total += $itemTotal;
        }

        return $total;
    }
}
