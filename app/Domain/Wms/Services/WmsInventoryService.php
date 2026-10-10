<?php

namespace App\Domain\Wms\Services;

use App\Domain\Catalogue\Models\Catalogue;
use App\Domain\Order\Models\PurchaseOrder;
use App\Domain\Wms\Http\Requests\AdjustStockRequest;
use App\Domain\Wms\Http\Requests\PutAwayStockRequest;
use App\Domain\Wms\Http\Requests\ReceiveStockRequest;
use App\Domain\Wms\Http\Requests\SetReorderLevelRequest;
use App\Domain\Wms\Http\Requests\StoreWarehouseBinRequest;
use App\Domain\Wms\Http\Requests\StoreWarehouseRequest;
use App\Domain\Wms\Http\Requests\TransferStockRequest;
use App\Domain\Wms\Http\Requests\UpdateWarehouseRequest;
use App\Domain\Wms\Support\WmsContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WmsInventoryService
{
    public function __construct(private WmsContext $context) {}

    public function warehouses(Request $request)
    {
        $company = $this->context->tenant($request);

        $warehouses = DB::table('warehouses')->where('company_id', $company->id)->orderBy('name')->get();
        foreach ($warehouses as $warehouse) {
            $warehouse->bins = DB::table('warehouse_bins')->where('company_id', $company->id)->where('warehouse_id', $warehouse->id)->orderBy('code')->get();
        }

        return response()->json(['data' => $warehouses]);
    }

    public function storeWarehouse(StoreWarehouseRequest $request)
    {
        $company = $this->context->tenant($request);
        $data = $request->validated();
        unset($data['company_id']);
        $id = (string) Str::uuid();
        DB::transaction(function () use ($data, $id, $company) {
            DB::table('warehouses')->insert($data + ['id' => $id, 'company_id' => $company->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->createSystemBins($company->id, $id);
        });

        return response()->json(['data' => DB::table('warehouses')->where('id', $id)->first()], 201);
    }

    public function bins(Request $request, string $warehouseId)
    {
        $company = $this->context->tenant($request);
        abort_unless(DB::table('warehouses')->where('id', $warehouseId)->where('company_id', $company->id)->exists(), 404, 'Warehouse tidak ditemukan.');
        $bins = DB::table('warehouse_bins')->where('company_id', $company->id)->where('warehouse_id', $warehouseId)->orderBy('code')->get();

        return response()->json(['data' => $bins]);
    }

    public function storeBin(StoreWarehouseBinRequest $request)
    {
        $company = $this->context->tenant($request);
        $data = $request->validated();
        abort_unless(DB::table('warehouses')->where('id', $data['warehouse_id'])->where('company_id', $company->id)->exists(), 404, 'Warehouse tidak ditemukan.');
        $id = (string) Str::uuid();
        DB::table('warehouse_bins')->insert([
            'id' => $id, 'company_id' => $company->id, 'warehouse_id' => $data['warehouse_id'],
            'code' => strtoupper($data['code']), 'name' => $data['name'], 'type' => $data['type'],
            'pick_priority' => $data['pick_priority'] ?? 100, 'capacity_units' => $data['capacity_units'] ?? null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(['data' => DB::table('warehouse_bins')->where('id', $id)->first()], 201);
    }

    private function createSystemBins(string $companyId, string $warehouseId): void
    {
        foreach ([['RECEIVING', 'Receiving', 'receiving'], ['STORAGE', 'Storage', 'storage'], ['QUARANTINE', 'Quarantine', 'quarantine']] as [$code, $name, $type]) {
            $exists = DB::table('warehouse_bins')->where('warehouse_id', $warehouseId)->where('code', $code)->exists();
            if (! $exists) {
                DB::table('warehouse_bins')->insert(['id' => (string) Str::uuid(), 'company_id' => $companyId, 'warehouse_id' => $warehouseId, 'code' => $code, 'name' => $name, 'type' => $type, 'status' => 'active', 'is_system' => true, 'pick_priority' => 100, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function updateWarehouse(UpdateWarehouseRequest $request, string $warehouseId)
    {
        $company = $this->context->tenant($request);
        $warehouse = DB::table('warehouses')->where('id', $warehouseId)->where('company_id', $company->id)->first();
        abort_unless($warehouse, 404, 'Warehouse tidak ditemukan.');
        $data = $request->validated();
        unset($data['company_id']);
        abort_if($data === [], 422, 'Tidak ada perubahan warehouse.');
        DB::table('warehouses')->where('id', $warehouseId)->where('company_id', $company->id)->update($data + ['updated_at' => now()]);

        return response()->json(['data' => DB::table('warehouses')->where('id', $warehouseId)->first()]);
    }

    public function catalogue(Request $request)
    {
        $company = $this->context->tenant($request);
        $items = Catalogue::query()->where(function ($query) use ($company) {
            $query->where('company_id', $company->id)->orWhereHas('company', fn ($vendor) => $vendor->where('type', 'vendor')->whereIn('status', ['approved', 'pending']));
        })->orderBy('name')->limit(500)->get(['id', 'item_code', 'name', 'uom', 'category']);

        return response()->json(['data' => $items]);
    }

    public function inboundOrders(Request $request)
    {
        $company = $this->context->tenant($request);
        $orders = PurchaseOrder::query()
            ->where('buyer_company_id', $company->id)
            ->whereIn('status', ['confirmed', 'shipping', 'completed'])
            ->orderByDesc('expected_receiving_date')
            ->get(['id', 'po_number', 'vendor_name', 'status', 'expected_receiving_date', 'is_historical', 'proposal_id'])
            ->map(function (PurchaseOrder $order) use ($company) {
                if ($order->is_historical) {
                    $lines = DB::table('historical_po_items')->where('purchase_order_id', $order->id)->get()->map(fn ($line) => ['catalogue_id' => null, 'sku' => $line->inventory_code, 'name' => $line->inventory_name, 'uom' => $line->uom, 'ordered_quantity' => (float) $line->qty]);
                } elseif ($order->proposal_id) {
                    $lines = DB::table('proposal_items as pi')->join('rfq_items as ri', 'ri.id', '=', 'pi.rfq_item_id')->join('catalogues as c', 'c.id', '=', 'ri.catalogue_id')->where('pi.proposal_id', $order->proposal_id)->select('c.id as catalogue_id', 'c.item_code as sku', 'c.name', 'c.uom', 'ri.qty as ordered_quantity')->get()->map(fn ($line) => (array) $line);
                } else {
                    $lines = collect();
                }
                $lines = $lines->map(function ($line) use ($order, $company) {
                    $received = (float) DB::table('warehouse_receipt_lines as rl')->join('warehouse_receipts as r', 'r.id', '=', 'rl.receipt_id')->where('r.company_id', $company->id)->where('r.purchase_order_id', $order->id)->where('rl.sku', $line['sku'])->sum('rl.received_quantity');
                    $line['received_quantity'] = $received;
                    $line['remaining_quantity'] = max(0, (float) $line['ordered_quantity'] - $received);

                    return $line;
                })->filter(fn ($line) => $line['remaining_quantity'] > 0)->values();
                $order->setAttribute('lines', $lines);

                return $order;
            })->filter(fn (PurchaseOrder $order) => $order->lines->isNotEmpty())->values();

        return response()->json(['data' => $orders]);
    }

    public function stock(Request $request)
    {
        $company = $this->context->tenant($request);
        $query = DB::table('warehouse_stock as s')
            ->join('warehouses as w', 'w.id', '=', 's.warehouse_id')
            ->leftJoin('catalogues as c', 'c.id', '=', 's.catalogue_id')
            ->where('s.company_id', $company->id)
            ->select('s.*', 'w.name as warehouse_name', 'w.code as warehouse_code', 'c.category as catalogue_category');
        if ($request->filled('warehouse_id')) {
            $query->where('s.warehouse_id', $request->query('warehouse_id'));
        }
        if ($request->filled('search')) {
            $query->where(fn ($q) => $q->where('s.sku', 'like', '%'.$request->query('search').'%')->orWhere('s.item_name', 'like', '%'.$request->query('search').'%'));
        }

        return response()->json(['data' => $query->orderBy('s.item_name')->paginate(100)]);
    }

    public function setReorderLevel(SetReorderLevelRequest $request, int $stockId)
    {
        $company = $this->context->tenant($request);
        $data = $request->validated();
        $stock = $this->context->stockForTenant($company, $stockId);
        DB::table('warehouse_stock')->where('id', $stock->id)->update(['reorder_level' => $data['reorder_level'], 'updated_at' => now()]);

        return response()->json(['data' => DB::table('warehouse_stock')->where('id', $stock->id)->first()]);
    }

    public function receive(ReceiveStockRequest $request)
    {
        $company = $this->context->tenant($request);
        $data = $request->validated();
        abort_unless(DB::table('warehouses')->where('id', $data['warehouse_id'])->where('company_id', $company->id)->exists(), 404, 'Warehouse tidak ditemukan.');
        $receipt = DB::transaction(function () use ($company, $data, $request) {
            DB::table('companies')->where('id', $company->id)->lockForUpdate()->first();
            $existing = DB::table('warehouse_receipts')->where('company_id', $company->id)->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                return ['receipt' => $existing, 'replayed' => true];
            }
            $purchaseOrder = ! empty($data['purchase_order_id'])
                ? PurchaseOrder::query()->whereKey($data['purchase_order_id'])->where('buyer_company_id', $company->id)->lockForUpdate()->first()
                : null;
            if (! empty($data['purchase_order_id'])) {
                abort_unless($purchaseOrder, 404, 'Purchase order tidak ditemukan untuk perusahaan ini.');
            }
            $receivingBin = DB::table('warehouse_bins')->where('company_id', $company->id)->where('warehouse_id', $data['warehouse_id'])->where('type', 'receiving')->where('status', 'active')->orderBy('is_system')->first();
            if (! $receivingBin) {
                $this->createSystemBins($company->id, $data['warehouse_id']);
                $receivingBin = DB::table('warehouse_bins')->where('company_id', $company->id)->where('warehouse_id', $data['warehouse_id'])->where('type', 'receiving')->where('status', 'active')->first();
            }
            $receiptId = (string) Str::uuid();
            $receiptNumber = 'RCV-'.now()->format('Ymd').'-'.Str::upper(Str::random(8));
            $receiptReference = $purchaseOrder?->po_number ?? ($data['reference'] ?? $receiptNumber);
            DB::table('warehouse_receipts')->insert([
                'id' => $receiptId, 'company_id' => $company->id, 'warehouse_id' => $data['warehouse_id'],
                'purchase_order_id' => $purchaseOrder?->id, 'receiving_bin_id' => $receivingBin->id,
                'received_by' => $request->user()->id, 'receipt_number' => $receiptNumber,
                'idempotency_key' => $data['idempotency_key'], 'status' => 'received', 'reference' => $receiptReference,
                'notes' => $data['notes'] ?? null, 'received_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($purchaseOrder) {
                $requestedBySku = [];
                $orderedBySku = [];
                foreach ($data['lines'] as $line) {
                    $sku = $this->context->skuForTenant($company, $line, true)->code;
                    $ordered = $purchaseOrder->is_historical
                        ? DB::table('historical_po_items')->where('purchase_order_id', $purchaseOrder->id)->where('inventory_code', $sku)->sum('qty')
                        : DB::table('proposal_items as pi')->join('rfq_items as ri', 'ri.id', '=', 'pi.rfq_item_id')->join('catalogues as c', 'c.id', '=', 'ri.catalogue_id')->where('pi.proposal_id', $purchaseOrder->proposal_id)->where('c.item_code', $sku)->sum('ri.qty');
                    abort_unless($ordered > 0, 422, "SKU {$sku} is not part of this purchase order.");
                    $requestedBySku[$sku] = ($requestedBySku[$sku] ?? 0) + (float) $line['received_quantity'];
                    $orderedBySku[$sku] = (float) $ordered;
                }
                foreach ($requestedBySku as $sku => $requested) {
                    $received = DB::table('warehouse_receipt_lines as rl')->join('warehouse_receipts as r', 'r.id', '=', 'rl.receipt_id')->where('r.company_id', $company->id)->where('r.purchase_order_id', $purchaseOrder->id)->where('rl.sku', $sku)->sum('rl.received_quantity');
                    abort_unless((float) $received + $requested <= $orderedBySku[$sku], 422, "Received quantity exceeds ordered quantity for SKU {$sku}.");
                }
            }

            foreach ($data['lines'] as $line) {
                $skuRecord = $this->context->skuForTenant($company, $line, true);
                $catalogue = ! empty($skuRecord->catalogue_id) ? Catalogue::whereKey($skuRecord->catalogue_id)->first() : null;
                $sku = $skuRecord->code;
                $line['sku'] = $sku;
                $line['item_name'] = $skuRecord->name;
                $line['uom'] = $skuRecord->uom;
                $line['bin_location'] = $receivingBin->code;
                $stock = $this->context->getStock($company, $data['warehouse_id'], $line, true);
                DB::table('warehouse_stock')->where('id', $stock->id)->update(['bin_id' => $receivingBin->id, 'updated_at' => now()]);
                if ((float) $line['accepted_quantity'] > 0) {
                    DB::table('warehouse_stock')->where('id', $stock->id)->increment('on_hand', $line['accepted_quantity'], ['updated_at' => now()]);
                }
                $lineId = (string) Str::uuid();
                DB::table('warehouse_receipt_lines')->insert([
                    'id' => $lineId, 'receipt_id' => $receiptId, 'stock_id' => $stock->id, 'catalogue_id' => $catalogue?->id,
                    'sku' => $sku, 'item_name' => $line['item_name'], 'uom' => $line['uom'], 'ordered_quantity' => null,
                    'received_quantity' => $line['received_quantity'], 'accepted_quantity' => $line['accepted_quantity'],
                    'rejected_quantity' => $line['rejected_quantity'], 'condition' => $line['condition'],
                    'inspection_notes' => $line['inspection_notes'] ?? null, 'created_at' => now(), 'updated_at' => now(),
                    'lot_number' => $line['lot_number'] ?? null, 'serial_number' => $line['serial_number'] ?? null,
                    'expiry_date' => $line['expiry_date'] ?? null, 'unit_cost' => $line['unit_cost'] ?? null,
                ]);
                if (! empty($line['lot_number']) || ! empty($line['serial_number']) || ! empty($line['expiry_date'])) {
                    DB::table('warehouse_lots')->insert([
                        'id' => (string) Str::uuid(), 'company_id' => $company->id, 'warehouse_id' => $data['warehouse_id'],
                        'stock_id' => $stock->id, 'bin_id' => $receivingBin->id, 'catalogue_id' => $catalogue?->id,
                        'sku' => $sku, 'lot_number' => $line['lot_number'] ?? null, 'serial_number' => $line['serial_number'] ?? null,
                        'expiry_date' => $line['expiry_date'] ?? null, 'on_hand' => $line['accepted_quantity'],
                        'unit_cost' => $line['unit_cost'] ?? null, 'received_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
                if (isset($line['unit_cost'])) {
                    DB::table('warehouse_stock')->where('id', $stock->id)->update(['average_unit_cost' => $line['unit_cost'], 'updated_at' => now()]);
                }
                DB::table('warehouse_transactions')->insert([
                    'company_id' => $company->id, 'warehouse_id' => $data['warehouse_id'], 'stock_id' => $stock->id,
                    'purchase_order_id' => $purchaseOrder?->id, 'receipt_id' => $receiptId, 'to_bin_id' => $receivingBin->id,
                    'type' => 'receiving', 'quantity' => $line['accepted_quantity'], 'reference' => $receiptReference,
                    'notes' => $line['rejected_quantity'] > 0 ? "Rejected {$line['rejected_quantity']}: ".($line['inspection_notes'] ?? $line['condition']) : ($data['notes'] ?? null),
                    'user_id' => $request->user()->id, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            return ['receipt' => DB::table('warehouse_receipts')->where('id', $receiptId)->first(), 'replayed' => false];
        });

        return response()->json(['data' => $receipt['receipt'], 'replayed' => $receipt['replayed']], $receipt['replayed'] ? 200 : 201);
    }

    public function receipts(Request $request)
    {
        $company = $this->context->tenant($request);
        $rows = DB::table('warehouse_receipts as r')
            ->join('warehouses as w', 'w.id', '=', 'r.warehouse_id')
            ->where('r.company_id', $company->id)
            ->orderByDesc('r.received_at')
            ->select('r.*', 'w.name as warehouse_name')
            ->paginate(100);
        $rows->getCollection()->transform(function ($receipt) {
            $receipt->lines = DB::table('warehouse_receipt_lines')->where('receipt_id', $receipt->id)->get();

            return $receipt;
        });

        return response()->json(['data' => $rows]);
    }

    public function putaway(PutAwayStockRequest $request)
    {
        $company = $this->context->tenant($request);
        $data = $request->validated();
        DB::transaction(function () use ($company, $data, $request) {
            $fromBin = DB::table('warehouse_bins')->where('id', $data['from_bin_id'])->where('company_id', $company->id)->where('warehouse_id', $data['warehouse_id'])->where('status', 'active')->first();
            $toBin = DB::table('warehouse_bins')->where('id', $data['to_bin_id'])->where('company_id', $company->id)->where('warehouse_id', $data['warehouse_id'])->where('status', 'active')->first();
            abort_unless($fromBin && $toBin && in_array($toBin->type, ['storage', 'picking', 'packing'], true), 422, 'Bin tujuan harus merupakan bin storage, picking, atau packing yang aktif.');
            $source = DB::table('warehouse_stock')->where('company_id', $company->id)->where('warehouse_id', $data['warehouse_id'])->where('sku', $data['sku'])->where(fn ($query) => $query->where('bin_id', $fromBin->id)->orWhere(fn ($legacy) => $legacy->whereNull('bin_id')->where('bin_location', $fromBin->code)))->lockForUpdate()->first();
            abort_unless($source, 404, 'Stok pada bin asal tidak ditemukan.');
            abort_unless((float) $source->on_hand - (float) $source->allocated >= (float) $data['quantity'], 422, 'Stok tersedia di bin asal tidak mencukupi.');
            DB::table('warehouse_stock')->where('id', $source->id)->decrement('on_hand', $data['quantity']);
            DB::table('warehouse_stock')->where('id', $source->id)->update(['bin_id' => $fromBin->id, 'updated_at' => now()]);
            $targetLine = ['sku' => $source->sku, 'item_name' => $source->item_name, 'uom' => $source->uom, 'catalogue_id' => $source->catalogue_id, 'bin_location' => $toBin->code];
            $target = $this->context->getStock($company, $data['warehouse_id'], $targetLine, true);
            DB::table('warehouse_stock')->where('id', $target->id)->increment('on_hand', $data['quantity']);
            DB::table('warehouse_stock')->where('id', $target->id)->update(['bin_id' => $toBin->id, 'updated_at' => now()]);
            foreach ([[$source, -(float) $data['quantity']], [$target, (float) $data['quantity']]] as [$stock, $quantity]) {
                DB::table('warehouse_transactions')->insert(['company_id' => $company->id, 'warehouse_id' => $data['warehouse_id'], 'stock_id' => $stock->id, 'type' => 'putaway', 'quantity' => $quantity, 'from_bin_id' => $fromBin->id, 'to_bin_id' => $toBin->id, 'reference' => $data['reference'] ?? null, 'notes' => $fromBin->code.' → '.$toBin->code, 'user_id' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            }
        });

        return response()->json(['message' => 'Put away completed.']);
    }

    public function adjust(AdjustStockRequest $request)
    {
        $company = $this->context->tenant($request);
        $data = $request->validated();
        DB::transaction(function () use ($company, $data, $request) {
            $stock = $this->context->getStock($company, $data['warehouse_id'], ['sku' => $data['sku'], 'bin_location' => $data['bin_location']]);
            $stock = DB::table('warehouse_stock')->where('id', $stock->id)->lockForUpdate()->first();
            $next = (float) $stock->on_hand + (float) $data['quantity'];
            abort_unless($next >= (float) $stock->allocated, 422, 'Penyesuaian tidak boleh mengurangi stok yang sudah dialokasikan.');
            DB::table('warehouse_stock')->where('id', $stock->id)->update(['on_hand' => $next, 'updated_at' => now()]);
            DB::table('warehouse_transactions')->insert(['company_id' => $company->id, 'warehouse_id' => $data['warehouse_id'], 'stock_id' => $stock->id, 'type' => 'adjustment', 'quantity' => $data['quantity'], 'reference' => 'ADJ-'.now()->format('YmdHis'), 'notes' => $data['reason'], 'user_id' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
        });

        return response()->json(['message' => 'Stock adjustment recorded.']);
    }

    public function transfer(TransferStockRequest $request)
    {
        $company = $this->context->tenant($request);
        $data = $request->validated();
        DB::transaction(function () use ($company, $data, $request) {
            foreach ([$data['from_warehouse_id'], $data['to_warehouse_id']] as $warehouseId) {
                abort_unless(DB::table('warehouses')->where('id', $warehouseId)->where('company_id', $company->id)->exists(), 404, 'Warehouse tidak ditemukan untuk tenant ini.');
            }
            $source = DB::table('warehouse_stock')->where('company_id', $company->id)->where('warehouse_id', $data['from_warehouse_id'])->where('sku', $data['sku'])->where('bin_location', $data['from_bin'])->lockForUpdate()->first();
            abort_unless($source, 404, 'SKU tidak ditemukan pada warehouse/bin asal.');
            abort_unless((float) $source->on_hand - (float) $source->allocated >= (float) $data['quantity'], 422, 'Stok tersedia tidak mencukupi untuk transfer.');
            DB::table('warehouse_stock')->where('id', $source->id)->decrement('on_hand', $data['quantity']);
            $target = $this->context->getStock($company, $data['to_warehouse_id'], ['sku' => $source->sku, 'item_name' => $source->item_name, 'uom' => $source->uom, 'catalogue_id' => $source->catalogue_id, 'bin_location' => $data['to_bin']], true);
            DB::table('warehouse_stock')->where('id', $target->id)->increment('on_hand', $data['quantity']);
            $reference = $data['reference'] ?? 'TRF-'.now()->format('YmdHis');
            foreach ([[$source, $data['from_warehouse_id'], -(float) $data['quantity']], [$target, $data['to_warehouse_id'], (float) $data['quantity']]] as [$stock, $warehouseId, $quantity]) {
                DB::table('warehouse_transactions')->insert(['company_id' => $company->id, 'warehouse_id' => $warehouseId, 'stock_id' => $stock->id, 'type' => 'transfer', 'quantity' => $quantity, 'reference' => $reference, 'notes' => $data['from_warehouse_id'].' → '.$data['to_warehouse_id'], 'user_id' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            }
        });

        return response()->json(['message' => 'Stock transfer completed.']);
    }
}
