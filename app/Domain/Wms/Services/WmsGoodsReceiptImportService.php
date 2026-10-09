<?php

namespace App\Domain\Wms\Services;

use App\Domain\Receipt\Models\GoodsReceipt;
use App\Domain\Wms\Http\Requests\ImportGoodsReceiptsRequest;
use App\Domain\Wms\Support\WmsContext;
use Illuminate\Http\Request;
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
            ->with(['deliveryOrder.purchaseOrder.historicalItems'])
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

        $repairedReceipts = $this->repairLegacyNamesForCompany($company);
        $repairedStock = $this->repairLegacyStockForCompany($company);

        return response()->json([
            'data' => $imported,
            'imported_count' => count($imported),
            'repaired_receipt_count' => $repairedReceipts,
            'repaired_stock_count' => $repairedStock,
        ]);
    }

    public function syncGoodsReceipt(object $company, string $warehouseId, GoodsReceipt $goodsReceipt, string $userId): array
    {
        abort_unless(DB::table('company_apps')->where('company_id', $company->id)->where('app_key', 'wms-inventory')->whereNotNull('installed_at')->exists(), 422, 'WMS & Inventory is not installed for this company.');
        abort_unless(DB::table('warehouses')->where('id', $warehouseId)->where('company_id', $company->id)->where('status', 'active')->exists(), 422, 'Warehouse tujuan tidak valid atau tidak aktif.');

        return DB::transaction(fn () => $this->importOne($company, $warehouseId, $goodsReceipt, $userId));
    }

    public function repairLegacyInventory(Request $request)
    {
        $company = $this->context->tenant($request);

        return response()->json([
            'repaired_receipt_count' => $this->repairLegacyNamesForCompany($company),
            'repaired_stock_count' => $this->repairLegacyStockForCompany($company),
        ]);
    }

    private function importOne(object $company, string $warehouseId, GoodsReceipt $goodsReceipt, string $userId): array
    {
        $goodsReceipt->loadMissing(['deliveryOrder.purchaseOrder.historicalItems']);
        $existing = DB::table('warehouse_receipts')->where('goods_receipt_id', $goodsReceipt->id)->first();
        if ($existing) {
            $this->repairImportedReceiptNames($existing->id, $goodsReceipt);

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
            $stock = $this->context->getStock($company, $warehouseId, [
                'sku' => $line['sku'],
                'item_name' => $line['item_name'],
                'uom' => $line['uom'],
                'catalogue_id' => $line['catalogue_id'] ?? null,
                'bin_location' => $bin->code,
            ], true);
            $this->syncStockIdentity($stock, $line);
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
        $po = $goodsReceipt->deliveryOrder?->purchaseOrder;
        $catalog = $po ? $this->purchaseOrderLineCatalog($po) : [];
        $indexes = $this->indexPoCatalog($catalog);

        $inspection = $this->normalizeJsonArray($goodsReceipt->items_inspection);
        if ($inspection === []) {
            $inspection = $this->normalizeJsonArray($goodsReceipt->accepted_items);
        }

        if ($inspection !== []) {
            $mapped = collect($inspection)
                ->map(fn (array $item) => $this->mapInspectionLine($item, $indexes, $goodsReceipt))
                ->filter(fn (array $line) => $line['accepted_quantity'] > 0 || $line['received_quantity'] > 0)
                ->values();
            if ($mapped->isNotEmpty()) {
                return $mapped->all();
            }
        }

        $fromPo = $catalog !== []
            ? $this->linesFromPurchaseOrder($catalog, (float) $goodsReceipt->received_qty, $goodsReceipt->inspection_notes)
            : [];
        if ($fromPo !== []) {
            return $fromPo;
        }

        return [[
            'sku' => 'LEGACY-GR-'.Str::upper(Str::substr(str_replace('-', '', $goodsReceipt->id), 0, 12)),
            'item_name' => 'Imported legacy Goods Receipt',
            'catalogue_id' => null,
            'uom' => 'unit',
            'received_quantity' => (float) $goodsReceipt->received_qty,
            'accepted_quantity' => (float) $goodsReceipt->received_qty,
            'rejected_quantity' => 0,
            'inspection_notes' => $goodsReceipt->inspection_notes,
        ]];
    }

    /**
     * @return list<array{po_item_id: string, sku: string, item_name: string, uom: string, catalogue_id: ?string, ordered_quantity: float}>
     */
    private function purchaseOrderLineCatalog(object $po): array
    {
        $poId = (string) $po->id;

        $historical = DB::table('historical_po_items')->where('purchase_order_id', $poId)->get();
        if ($historical->isNotEmpty()) {
            return $historical->map(function ($line) {
                $code = trim((string) ($line->inventory_code ?? ''));
                $name = trim((string) ($line->inventory_name ?? ''));
                $sku = strtoupper($code !== '' ? $code : 'PO-'.$line->id);

                return [
                    'po_item_id' => (string) $line->id,
                    'sku' => $sku,
                    'item_name' => $name !== '' ? $name : $sku,
                    'uom' => $line->uom ?: 'unit',
                    'catalogue_id' => null,
                    'ordered_quantity' => (float) $line->qty,
                ];
            })->all();
        }

        if (! empty($po->proposal_id)) {
            $proposalLines = DB::table('proposal_items as pi')
                ->join('rfq_items as ri', 'ri.id', '=', 'pi.rfq_item_id')
                ->leftJoin('catalogues as c', 'c.id', '=', 'ri.catalogue_id')
                ->where('pi.proposal_id', $po->proposal_id)
                ->select(
                    'ri.id as po_item_id',
                    'c.id as catalogue_id',
                    'c.item_code as sku',
                    'c.name as item_name',
                    'c.uom',
                    'ri.qty as ordered_quantity',
                )
                ->get();
            if ($proposalLines->isNotEmpty()) {
                return $this->mapCatalogRows($proposalLines);
            }
        }

        if (! empty($po->rfq_id)) {
            $rfqLines = DB::table('rfq_items as ri')
                ->leftJoin('catalogues as c', 'c.id', '=', 'ri.catalogue_id')
                ->where('ri.rfq_id', $po->rfq_id)
                ->select(
                    'ri.id as po_item_id',
                    'c.id as catalogue_id',
                    'c.item_code as sku',
                    'c.name as item_name',
                    'c.uom',
                    'ri.qty as ordered_quantity',
                )
                ->get();
            if ($rfqLines->isNotEmpty()) {
                return $this->mapCatalogRows($rfqLines);
            }
        }

        return [];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, object>  $rows
     * @return list<array{po_item_id: string, sku: string, item_name: string, uom: string, catalogue_id: ?string, ordered_quantity: float}>
     */
    private function mapCatalogRows($rows): array
    {
        return $rows->map(function ($line) {
            $sku = strtoupper(trim((string) ($line->sku ?? '')));
            $name = trim((string) ($line->item_name ?? ''));
            if ($sku === '') {
                $sku = 'PO-'.$line->po_item_id;
            }
            if ($name === '') {
                $name = $sku;
            }

            return [
                'po_item_id' => (string) $line->po_item_id,
                'sku' => $sku,
                'item_name' => $name,
                'uom' => $line->uom ?: 'unit',
                'catalogue_id' => ! empty($line->catalogue_id) ? (string) $line->catalogue_id : null,
                'ordered_quantity' => (float) $line->ordered_quantity,
            ];
        })->all();
    }

    /**
     * @param  list<array{po_item_id: string, sku: string, item_name: string, uom: string, catalogue_id: ?string, ordered_quantity: float}>  $catalog
     * @return list<array<string, mixed>>
     */
    private function linesFromPurchaseOrder(array $catalog, float $receivedQty, ?string $inspectionNotes): array
    {
        $totalOrdered = array_sum(array_map(fn (array $row) => max(0, $row['ordered_quantity']), $catalog));
        $receivedQty = max(0, $receivedQty);
        $lines = [];

        foreach ($catalog as $row) {
            $ordered = max(0, $row['ordered_quantity']);
            if ($ordered <= 0 && $receivedQty <= 0) {
                continue;
            }
            if ($totalOrdered > 0) {
                $qty = $receivedQty * ($ordered / $totalOrdered);
            } else {
                $qty = $receivedQty / max(1, count($catalog));
            }
            if ($qty <= 0) {
                continue;
            }
            $lines[] = [
                'sku' => $row['sku'],
                'item_name' => $row['item_name'],
                'catalogue_id' => $row['catalogue_id'],
                'uom' => $row['uom'],
                'received_quantity' => $qty,
                'accepted_quantity' => $qty,
                'rejected_quantity' => 0.0,
                'inspection_notes' => $inspectionNotes,
            ];
        }

        return $lines;
    }

    /**
     * @param  list<array{po_item_id: string, sku: string, item_name: string, uom: string, catalogue_id: ?string, ordered_quantity: float}>  $catalog
     * @return array{by_id: array<string, array>, by_sku: array<string, array>}
     */
    private function indexPoCatalog(array $catalog): array
    {
        $byId = [];
        $bySku = [];
        foreach ($catalog as $row) {
            $byId[$row['po_item_id']] = $row;
            $bySku[strtoupper($row['sku'])] = $row;
        }

        return ['by_id' => $byId, 'by_sku' => $bySku];
    }

    /**
     * @param  array{by_id: array<string, array>, by_sku: array<string, array>}  $indexes
     * @return array<string, mixed>
     */
    private function mapInspectionLine(array $item, array $indexes, GoodsReceipt $goodsReceipt): array
    {
        $poRef = null;
        if (! empty($item['po_item_id']) && isset($indexes['by_id'][(string) $item['po_item_id']])) {
            $poRef = $indexes['by_id'][(string) $item['po_item_id']];
        }

        $skuCandidate = $item['inventory_code'] ?? $item['sku'] ?? $poRef['sku'] ?? null;
        $sku = strtoupper(trim((string) ($skuCandidate ?: ('LEGACY-'.($item['po_item_id'] ?? Str::uuid())))));

        $nameCandidate = $item['inventory_name'] ?? $item['item_name'] ?? $item['name'] ?? $poRef['item_name'] ?? null;
        $itemName = trim((string) ($nameCandidate ?: ''));
        if ($itemName === '' || $this->isLegacyPlaceholderName($itemName)) {
            $itemName = $poRef['item_name'] ?? $sku;
        }

        $received = (float) ($item['received_qty'] ?? $item['delivered_qty'] ?? $item['ordered_qty'] ?? 0);
        $rejected = (float) ($item['rejected_qty'] ?? 0);
        $accepted = (float) ($item['accepted_qty'] ?? max(0, $received - $rejected));

        return [
            'sku' => $sku,
            'item_name' => $itemName,
            'catalogue_id' => $poRef['catalogue_id'] ?? ($item['catalogue_id'] ?? null),
            'uom' => $item['uom'] ?? $poRef['uom'] ?? 'unit',
            'received_quantity' => $received,
            'accepted_quantity' => $accepted,
            'rejected_quantity' => $rejected,
            'inspection_notes' => $item['condition_notes'] ?? $item['rejection_reason'] ?? $goodsReceipt->inspection_notes,
        ];
    }

    private function normalizeJsonArray(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_string($decoded)) {
                $decoded = json_decode($decoded, true);
            }
            $value = $decoded;
        }
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, fn ($row) => is_array($row)));
    }

    private function isLegacyPlaceholderName(?string $name): bool
    {
        if ($name === null || trim($name) === '') {
            return true;
        }

        return (bool) preg_match('/^Imported(\s+legacy)?\s+Goods Receipt/i', trim($name));
    }

    private function syncStockIdentity(object $stock, array $line): void
    {
        $itemName = trim((string) ($line['item_name'] ?? ''));
        if ($itemName === '' || $this->isLegacyPlaceholderName($itemName)) {
            return;
        }

        $stockUpdates = [];
        if ($this->isLegacyPlaceholderName($stock->item_name ?? null) || (string) ($stock->item_name ?? '') !== $itemName) {
            $stockUpdates['item_name'] = $itemName;
        }
        if (! empty($line['catalogue_id']) && empty($stock->catalogue_id)) {
            $stockUpdates['catalogue_id'] = $line['catalogue_id'];
        }
        if ($stockUpdates !== []) {
            $stockUpdates['updated_at'] = now();
            DB::table('warehouse_stock')->where('id', $stock->id)->update($stockUpdates);
        }

        if (empty($stock->wms_sku_id)) {
            return;
        }
        $skuRow = DB::table('wms_skus')->where('id', $stock->wms_sku_id)->first();
        if (! $skuRow) {
            return;
        }
        if ($this->isLegacyPlaceholderName($skuRow->name ?? null) || (string) $skuRow->name !== $itemName) {
            DB::table('wms_skus')->where('id', $skuRow->id)->update(['name' => $itemName, 'updated_at' => now()]);
        }
    }

    private function repairLegacyNamesForCompany(object $company): int
    {
        $receipts = DB::table('warehouse_receipts')
            ->where('company_id', $company->id)
            ->where(function ($query) {
                $query->whereNotNull('goods_receipt_id')->orWhereNotNull('purchase_order_id');
            })
            ->get(['id', 'goods_receipt_id', 'purchase_order_id']);

        $repaired = 0;
        foreach ($receipts as $receipt) {
            $before = DB::table('warehouse_receipt_lines')->where('receipt_id', $receipt->id)->pluck('item_name');
            if ($receipt->goods_receipt_id) {
                $goodsReceipt = GoodsReceipt::query()
                    ->with(['deliveryOrder.purchaseOrder.historicalItems'])
                    ->find($receipt->goods_receipt_id);
                if ($goodsReceipt) {
                    $this->repairImportedReceiptNames($receipt->id, $goodsReceipt);
                }
            } elseif ($receipt->purchase_order_id) {
                $this->repairReceiptNamesFromPurchaseOrder($receipt->id, (string) $receipt->purchase_order_id);
            }
            $after = DB::table('warehouse_receipt_lines')->where('receipt_id', $receipt->id)->pluck('item_name');
            if ($before->join('|') !== $after->join('|')) {
                $repaired++;
            }
        }

        return $repaired;
    }

    private function repairLegacyStockForCompany(object $company): int
    {
        $stocks = DB::table('warehouse_stock')
            ->where('company_id', $company->id)
            ->where(function ($query) {
                $query->where('sku', 'like', 'LEGACY-GR-%')
                    ->orWhere('item_name', 'like', 'Imported%Goods Receipt%');
            })
            ->get();

        $repaired = 0;
        foreach ($stocks as $stock) {
            $catalog = $this->catalogForStockRecord($stock);
            if ($catalog === []) {
                continue;
            }
            if ($this->applyCatalogIdentityToStock($stock, $catalog)) {
                $repaired++;
            }
        }

        return $repaired;
    }

    /**
     * @return list<array{po_item_id: string, sku: string, item_name: string, uom: string, catalogue_id: ?string, ordered_quantity: float}>
     */
    private function catalogForStockRecord(object $stock): array
    {
        $poId = DB::table('warehouse_receipt_lines as rl')
            ->join('warehouse_receipts as r', 'r.id', '=', 'rl.receipt_id')
            ->where('rl.stock_id', $stock->id)
            ->whereNotNull('r.purchase_order_id')
            ->orderByDesc('r.created_at')
            ->value('r.purchase_order_id');

        if ($poId) {
            $po = DB::table('purchase_orders')->where('id', $poId)->first();

            return $po ? $this->purchaseOrderLineCatalog($po) : [];
        }

        $goodsReceiptId = DB::table('warehouse_receipt_lines as rl')
            ->join('warehouse_receipts as r', 'r.id', '=', 'rl.receipt_id')
            ->where('rl.stock_id', $stock->id)
            ->whereNotNull('r.goods_receipt_id')
            ->orderByDesc('r.created_at')
            ->value('r.goods_receipt_id');

        if (! $goodsReceiptId) {
            $goodsReceiptId = $this->goodsReceiptIdFromLegacySku((string) $stock->sku);
        }

        if (! $goodsReceiptId) {
            return [];
        }

        $goodsReceipt = GoodsReceipt::query()
            ->with(['deliveryOrder.purchaseOrder.historicalItems'])
            ->find($goodsReceiptId);
        $po = $goodsReceipt?->deliveryOrder?->purchaseOrder;

        return $po ? $this->purchaseOrderLineCatalog($po) : [];
    }

    private function goodsReceiptIdFromLegacySku(string $sku): ?string
    {
        if (! preg_match('/^LEGACY-GR-([A-F0-9]{12})$/i', strtoupper(trim($sku)), $matches)) {
            return null;
        }

        $prefix = strtolower($matches[1]);

        return GoodsReceipt::query()
            ->whereRaw("LOWER(REPLACE(id, '-', '')) LIKE ?", [$prefix.'%'])
            ->orderBy('created_at')
            ->value('id');
    }

    /**
     * @param  list<array{po_item_id: string, sku: string, item_name: string, uom: string, catalogue_id: ?string, ordered_quantity: float}>  $catalog
     */
    private function applyCatalogIdentityToStock(object $stock, array $catalog): bool
    {
        $primary = count($catalog) === 1 ? $catalog[0] : null;
        $itemName = $primary
            ? $primary['item_name']
            : collect($catalog)->pluck('item_name')->filter()->unique()->implode('; ');

        if ($itemName === '' || $this->isLegacyPlaceholderName($itemName)) {
            return false;
        }

        $line = [
            'item_name' => $itemName,
            'catalogue_id' => $primary['catalogue_id'] ?? null,
            'sku' => $primary['sku'] ?? $stock->sku,
            'uom' => $primary['uom'] ?? 'unit',
        ];

        $beforeName = (string) ($stock->item_name ?? '');
        $beforeSku = (string) ($stock->sku ?? '');

        $this->syncStockIdentity($stock, $line);

        if ($primary && str_starts_with(strtoupper($beforeSku), 'LEGACY-GR-')) {
            $this->retargetLegacySkuIfAvailable($stock, $primary);
            $stock = DB::table('warehouse_stock')->where('id', $stock->id)->first() ?? $stock;
        }

        DB::table('warehouse_receipt_lines')->where('stock_id', $stock->id)->update([
            'item_name' => $itemName,
            'updated_at' => now(),
        ]);

        return $beforeName !== $itemName || ($primary && strtoupper($beforeSku) !== strtoupper($primary['sku']));
    }

    /**
     * @param  array{po_item_id: string, sku: string, item_name: string, uom: string, catalogue_id: ?string, ordered_quantity: float}  $target
     */
    private function retargetLegacySkuIfAvailable(object $stock, array $target): void
    {
        $newSku = strtoupper(trim($target['sku']));
        if ($newSku === '' || $newSku === strtoupper((string) $stock->sku)) {
            return;
        }

        $conflict = DB::table('warehouse_stock')
            ->where('warehouse_id', $stock->warehouse_id)
            ->where('sku', $newSku)
            ->where('id', '!=', $stock->id)
            ->exists();
        if ($conflict) {
            return;
        }

        DB::table('warehouse_stock')->where('id', $stock->id)->update([
            'sku' => $newSku,
            'updated_at' => now(),
        ]);
        DB::table('warehouse_receipt_lines')->where('stock_id', $stock->id)->update([
            'sku' => $newSku,
            'updated_at' => now(),
        ]);

        if (! empty($stock->wms_sku_id)) {
            DB::table('wms_skus')->where('id', $stock->wms_sku_id)->update([
                'code' => $newSku,
                'name' => $target['item_name'],
                'uom' => $target['uom'],
                'catalogue_id' => $target['catalogue_id'],
                'updated_at' => now(),
            ]);
        }
    }

    private function repairReceiptNamesFromPurchaseOrder(string $warehouseReceiptId, string $purchaseOrderId): void
    {
        $po = DB::table('purchase_orders')->where('id', $purchaseOrderId)->first();
        if (! $po) {
            return;
        }

        $desiredLines = collect($this->purchaseOrderLineCatalog($po));
        if ($desiredLines->isEmpty()) {
            return;
        }

        $desiredBySku = $desiredLines->keyBy(fn (array $line) => strtoupper($line['sku']));
        $receiptLines = DB::table('warehouse_receipt_lines')->where('receipt_id', $warehouseReceiptId)->get();

        foreach ($receiptLines as $row) {
            $desired = $desiredBySku->get(strtoupper((string) $row->sku));
            if (! $desired && $receiptLines->count() === 1 && $desiredLines->count() === 1) {
                $desired = $desiredLines->first();
            }
            if (! $desired) {
                continue;
            }
            $name = trim((string) ($desired['item_name'] ?? ''));
            if ($name === '' || $this->isLegacyPlaceholderName($name)) {
                continue;
            }
            DB::table('warehouse_receipt_lines')->where('id', $row->id)->update([
                'item_name' => $name,
                'updated_at' => now(),
            ]);
            if ($row->stock_id) {
                $stock = DB::table('warehouse_stock')->where('id', $row->stock_id)->first();
                if ($stock) {
                    $this->applyCatalogIdentityToStock($stock, $desiredLines->all());
                }
            }
        }
    }

    private function repairImportedReceiptNames(string $warehouseReceiptId, GoodsReceipt $goodsReceipt): void
    {
        $desiredLines = collect($this->linesFor($goodsReceipt));
        $desiredBySku = $desiredLines->keyBy(fn (array $line) => strtoupper($line['sku']));
        if ($desiredBySku->isEmpty()) {
            return;
        }

        $receiptLines = DB::table('warehouse_receipt_lines')->where('receipt_id', $warehouseReceiptId)->get();
        foreach ($receiptLines as $row) {
            $desired = $desiredBySku->get(strtoupper((string) $row->sku));
            if (! $desired && $receiptLines->count() === 1 && $desiredLines->count() === 1) {
                $desired = $desiredLines->first();
            }
            if (! $desired) {
                continue;
            }
            $name = trim((string) ($desired['item_name'] ?? ''));
            if ($name === '' || $this->isLegacyPlaceholderName($name)) {
                continue;
            }
            if (! $this->isLegacyPlaceholderName($row->item_name ?? null) && (string) $row->item_name === $name) {
                continue;
            }
            DB::table('warehouse_receipt_lines')->where('id', $row->id)->update([
                'item_name' => $name,
                'updated_at' => now(),
            ]);
            if ($row->stock_id) {
                $stock = DB::table('warehouse_stock')->where('id', $row->stock_id)->first();
                if ($stock) {
                    if ($this->isLegacyPlaceholderName($name)) {
                        $catalog = $this->catalogForStockRecord($stock);
                        if ($catalog !== []) {
                            $this->applyCatalogIdentityToStock($stock, $catalog);
                        }
                    } else {
                        $this->syncStockIdentity($stock, $desired);
                        if (str_starts_with(strtoupper((string) $row->sku), 'LEGACY-GR-') && $desiredLines->count() === 1) {
                            $this->retargetLegacySkuIfAvailable($stock, $desiredLines->first());
                        }
                    }
                }
            }
        }
    }
}
