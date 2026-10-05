<?php

namespace App\Domain\Wms\Services;

use App\Domain\Receipt\Models\GoodsReceipt;
use App\Domain\Wms\Http\Requests\ImportGoodsReceiptsRequest;
use App\Domain\Wms\Support\WmsContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WmsGoodsReceiptImportService
{
    public function __construct(private WmsContext $context) {}

    public function import(ImportGoodsReceiptsRequest $request)
    {
        $company = $this->context->tenant($request);
        $data = $request->validated();
        abort_unless(DB::table('warehouses')->where('id', $data['warehouse_id'])->where('company_id', $company->id)->exists(), 404, 'Warehouse tidak ditemukan.');

        $query = GoodsReceipt::query()
            ->with('deliveryOrder.purchaseOrder')
            ->whereHas('deliveryOrder.purchaseOrder', fn ($po) => $po->where('buyer_company_id', $company->id))
            ->whereNotExists(function ($receipt) {
                $receipt->selectRaw('1')->from('warehouse_receipts')->whereColumn('warehouse_receipts.goods_receipt_id', 'goods_receipts.id');
            });
        if (! empty($data['goods_receipt_ids'])) {
            $query->whereIn('id', $data['goods_receipt_ids']);
        }
        $goodsReceipts = $query->orderBy('created_at')->get();
        $imported = [];
        foreach ($goodsReceipts as $goodsReceipt) {
            $imported[] = DB::transaction(fn () => $this->importOne($company, $data['warehouse_id'], $goodsReceipt, (string) $request->user()->id));
        }

        return response()->json(['data' => $imported, 'imported_count' => count($imported)]);
    }

    private function importOne(object $company, string $warehouseId, GoodsReceipt $goodsReceipt, string $userId): array
    {
        $existing = DB::table('warehouse_receipts')->where('goods_receipt_id', $goodsReceipt->id)->first();
        if ($existing) {
            return ['goods_receipt_id' => $goodsReceipt->id, 'warehouse_receipt_id' => $existing->id, 'replayed' => true];
        }
        $bin = DB::table('warehouse_bins')->where('company_id', $company->id)->where('warehouse_id', $warehouseId)->where('type', 'receiving')->where('status', 'active')->first();
        abort_unless($bin, 422, 'Warehouse membutuhkan receiving bin aktif untuk import Goods Receipt.');
        $po = $goodsReceipt->deliveryOrder?->purchaseOrder;
        abort_unless($po, 422, 'Goods Receipt tidak terhubung ke Purchase Order.');
        $receiptId = (string) Str::uuid();
        DB::table('warehouse_receipts')->insert([
            'id' => $receiptId, 'company_id' => $company->id, 'warehouse_id' => $warehouseId,
            'purchase_order_id' => $po->id, 'goods_receipt_id' => $goodsReceipt->id, 'receiving_bin_id' => $bin->id,
            'received_by' => $userId, 'receipt_number' => 'GR-'.Str::upper(Str::substr(str_replace('-', '', $goodsReceipt->id), 0, 12)),
            'idempotency_key' => $goodsReceipt->id, 'status' => 'imported', 'reference' => $po->po_number,
            'notes' => 'Imported from existing Goods Receipt.', 'received_at' => $goodsReceipt->created_at, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($this->linesFor($goodsReceipt) as $line) {
            $stock = $this->context->getStock($company, $warehouseId, ['sku' => $line['sku'], 'item_name' => $line['item_name'], 'uom' => $line['uom'], 'bin_location' => $bin->code], true);
            DB::table('warehouse_stock')->where('id', $stock->id)->update(['bin_id' => $bin->id, 'updated_at' => now()]);
            if ($line['accepted_quantity'] > 0) {
                DB::table('warehouse_stock')->where('id', $stock->id)->increment('on_hand', $line['accepted_quantity'], ['updated_at' => now()]);
            }
            DB::table('warehouse_receipt_lines')->insert([
                'id' => (string) Str::uuid(), 'receipt_id' => $receiptId, 'stock_id' => $stock->id,
                'sku' => $line['sku'], 'item_name' => $line['item_name'], 'uom' => $line['uom'],
                'received_quantity' => $line['received_quantity'], 'accepted_quantity' => $line['accepted_quantity'], 'rejected_quantity' => $line['rejected_quantity'],
                'condition' => $line['rejected_quantity'] > 0 ? 'damaged' : 'good', 'inspection_notes' => $line['inspection_notes'],
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('warehouse_transactions')->insert([
                'company_id' => $company->id, 'warehouse_id' => $warehouseId, 'stock_id' => $stock->id,
                'purchase_order_id' => $po->id, 'receipt_id' => $receiptId, 'to_bin_id' => $bin->id,
                'type' => 'receiving', 'quantity' => $line['accepted_quantity'], 'reference' => $po->po_number,
                'notes' => 'Imported from Goods Receipt '.$goodsReceipt->id, 'user_id' => $userId, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return ['goods_receipt_id' => $goodsReceipt->id, 'warehouse_receipt_id' => $receiptId, 'replayed' => false];
    }

    private function linesFor(GoodsReceipt $goodsReceipt): array
    {
        $inspection = is_array($goodsReceipt->items_inspection) ? $goodsReceipt->items_inspection : [];
        if ($inspection === []) {
            return [[
                'sku' => 'LEGACY-GR-'.Str::upper(Str::substr(str_replace('-', '', $goodsReceipt->id), 0, 12)),
                'item_name' => 'Imported legacy Goods Receipt', 'uom' => 'unit',
                'received_quantity' => (float) $goodsReceipt->received_qty, 'accepted_quantity' => (float) $goodsReceipt->received_qty,
                'rejected_quantity' => 0, 'inspection_notes' => $goodsReceipt->inspection_notes,
            ]];
        }

        return collect($inspection)->map(fn (array $item) => [
            'sku' => $item['inventory_code'] ?? $item['sku'] ?? ('LEGACY-'.($item['po_item_id'] ?? Str::uuid())),
            'item_name' => $item['inventory_name'] ?? $item['item_name'] ?? 'Imported Goods Receipt item',
            'uom' => $item['uom'] ?? 'unit',
            'received_quantity' => (float) ($item['received_qty'] ?? $item['delivered_qty'] ?? 0),
            'accepted_quantity' => (float) ($item['accepted_qty'] ?? (($item['received_qty'] ?? $item['delivered_qty'] ?? 0) - ($item['rejected_qty'] ?? 0))),
            'rejected_quantity' => (float) ($item['rejected_qty'] ?? 0),
            'inspection_notes' => $item['condition_notes'] ?? $item['rejection_reason'] ?? $goodsReceipt->inspection_notes,
        ])->all();
    }
}
