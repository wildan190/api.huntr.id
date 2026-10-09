<?php

namespace App\Domain\Wms\Services;

use App\Domain\Wms\Http\Requests\AllocateStockRequest;
use App\Domain\Wms\Http\Requests\CheckStockAvailabilityRequest;
use App\Domain\Wms\Http\Requests\PackWarehouseOrderRequest;
use App\Domain\Wms\Http\Requests\ShipWarehouseOrderRequest;
use App\Domain\Wms\Http\Requests\UpdateWarehouseOrderStatusRequest;
use App\Domain\Wms\Support\WmsContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WmsOrderService
{
    public function __construct(private WmsContext $context) {}

    public function allocate(AllocateStockRequest $request)
    {
        $company = $this->context->tenant($request);
        $data = $request->validated();
        $order = DB::transaction(function () use ($company, $data, $request) {
            abort_unless(DB::table('warehouses')->where('id', $data['warehouse_id'])->where('company_id', $company->id)->exists(), 404, 'Warehouse tidak ditemukan.');
            $this->ensureAvailability($company, $data['warehouse_id'], $data['lines'], true);
            $resolved = [];
            foreach ($data['lines'] as $line) {
                $sku = $this->context->skuForTenant($company, $line);
                $identity = $sku->code;
                $remaining = (float) $line['quantity'];
                $stockIds = DB::table('warehouse_stock as s')
                    ->leftJoin('warehouse_bins as b', 'b.id', '=', 's.bin_id')
                    ->where('s.company_id', $company->id)
                    ->where('s.warehouse_id', $data['warehouse_id'])
                    ->where('s.wms_sku_id', $sku->id)
                    ->where(function ($query) {
                        $query->whereIn('b.type', ['storage', 'picking'])
                            ->orWhere(function ($legacy) {
                                $legacy->whereNull('s.bin_id')->where(function ($bin) {
                                    $bin->whereNull('s.bin_location')->orWhereNotIn('s.bin_location', ['RECEIVING', 'QUARANTINE']);
                                });
                            });
                    })
                    ->orderByRaw('COALESCE(b.pick_priority, 99999)')
                    ->orderBy('s.bin_location')
                    ->pluck('s.id')
                    ->all();

                $lots = $stockIds !== []
                    ? DB::table('warehouse_stock')
                        ->whereIn('id', $stockIds)
                        ->orderByRaw($this->buildOrderByCase('id', $stockIds))
                        ->lockForUpdate()
                        ->get()
                    : collect();
                foreach ($lots as $lot) {
                    $available = (float) $lot->on_hand - (float) $lot->allocated;
                    if ($available <= 0 || $remaining <= 0) {
                        continue;
                    }
                    $pick = min($available, $remaining);
                    DB::table('warehouse_stock')->where('id', $lot->id)->increment('allocated', $pick);
                    $resolved[] = ['stock_id' => $lot->id, 'sku' => $lot->sku, 'item_name' => $lot->item_name, 'bin_location' => $lot->bin_location, 'quantity' => $pick];
                    $remaining -= $pick;
                }
                if ($remaining > 0) {
                    throw ValidationException::withMessages(['lines' => "Stok tidak cukup untuk {$sku->name} (SKU: {$sku->code})."]);
                }
            }
            $id = DB::table('warehouse_orders')->insertGetId(['company_id' => $company->id, 'warehouse_id' => $data['warehouse_id'], 'order_number' => $data['order_number'], 'status' => 'allocated', 'lines' => json_encode($resolved), 'user_id' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);

            return DB::table('warehouse_orders')->where('id', $id)->first();
        });

        return response()->json(['data' => $order], 201);
    }

    public function availability(CheckStockAvailabilityRequest $request)
    {
        $company = $this->context->tenant($request);
        $data = $request->validated();
        abort_unless(DB::table('warehouses')->where('id', $data['warehouse_id'])->where('company_id', $company->id)->where('status', 'active')->exists(), 404, 'Warehouse tidak ditemukan atau tidak aktif.');

        return response()->json([
            'data' => $this->availabilityForLines($company, $data['warehouse_id'], $data['lines']),
        ]);
    }

    private function ensureAvailability(object $company, string $warehouseId, array $lines, bool $lock = false): void
    {
        foreach ($this->availabilityForLines($company, $warehouseId, $lines, $lock) as $line) {
            if (! $line['sufficient']) {
                throw ValidationException::withMessages([
                    'lines' => "Stok {$line['item_name']} (SKU: {$line['sku']}) di gudang tidak cukup. Dibutuhkan {$line['requested']}, tersedia {$line['available']}, kurang {$line['shortfall']}.",
                ]);
            }
        }
    }

    private function availabilityForLines(object $company, string $warehouseId, array $lines, bool $lock = false): array
    {
        $requestedBySku = [];
        foreach ($lines as $line) {
            $sku = $this->context->skuForTenant($company, $line);
            $requestedBySku[$sku->id] = [
                'sku_id' => $sku->id,
                'sku' => $sku->code,
                'item_name' => $sku->name,
                'requested' => ($requestedBySku[$sku->id]['requested'] ?? 0) + (float) $line['quantity'],
            ];
        }

        return collect($requestedBySku)->map(function (array $line) use ($company, $warehouseId, $lock) {
            $stockIds = DB::table('warehouse_stock as s')
                ->leftJoin('warehouse_bins as b', 'b.id', '=', 's.bin_id')
                ->where('s.company_id', $company->id)
                ->where('s.warehouse_id', $warehouseId)
                ->where('s.wms_sku_id', $line['sku_id'])
                ->where(function ($query) {
                    $query->whereIn('b.type', ['storage', 'picking'])
                        ->orWhere(function ($legacy) {
                            $legacy->whereNull('s.bin_id')->where(function ($bin) {
                                $bin->whereNull('s.bin_location')->orWhereNotIn('s.bin_location', ['RECEIVING', 'QUARANTINE']);
                            });
                        });
                })
                ->pluck('s.id')
                ->all();

            if ($stockIds === []) {
                $available = 0;
            } else {
                $stockQuery = DB::table('warehouse_stock')
                    ->whereIn('id', $stockIds)
                    ->select('on_hand', 'allocated');
                if ($lock) {
                    $stockQuery->lockForUpdate();
                }
                $available = (float) $stockQuery
                    ->get()
                    ->sum(fn ($stock) => (float) $stock->on_hand - (float) $stock->allocated);
            }
            $shortfall = max(0, $line['requested'] - $available);

            return [...$line, 'available' => $available, 'shortfall' => $shortfall, 'sufficient' => $shortfall <= 0];
        })->values()->all();
    }

    public function pack(PackWarehouseOrderRequest $request, int $id)
    {
        $company = $this->context->tenant($request);
        $data = $request->validated();
        $order = DB::table('warehouse_orders')->where('company_id', $company->id)->where('id', $id)->first();
        abort_unless($order, 404);
        abort_unless($order->status === 'picking', 422, 'Order harus dikonfirmasi sebagai sedang dipicking sebelum dikemas.');
        DB::table('warehouse_orders')->where('id', $id)->update(['status' => 'packed', 'packing_notes' => $data['packing_notes'] ?? null, 'packed_at' => now(), 'updated_at' => now()]);

        return response()->json(['message' => 'Order packed.', 'data' => DB::table('warehouse_orders')->where('id', $id)->first()]);
    }

    public function startPicking(UpdateWarehouseOrderStatusRequest $request, int $id)
    {
        $company = $this->context->tenant($request);
        $order = DB::table('warehouse_orders')->where('company_id', $company->id)->where('id', $id)->first();
        abort_unless($order, 404);
        abort_unless($order->status === 'allocated', 422, 'Hanya order yang sudah dialokasikan dapat mulai dipicking.');
        DB::table('warehouse_orders')->where('id', $id)->update(['status' => 'picking', 'updated_at' => now()]);

        return response()->json(['message' => 'Picking started.', 'data' => DB::table('warehouse_orders')->where('id', $id)->first()]);
    }

    public function release(UpdateWarehouseOrderStatusRequest $request, int $id)
    {
        $company = $this->context->tenant($request);
        $order = DB::transaction(function () use ($company, $id) {
            $order = DB::table('warehouse_orders')->where('company_id', $company->id)->where('id', $id)->lockForUpdate()->first();
            abort_unless($order, 404);
            abort_unless(in_array($order->status, ['allocated', 'picking'], true), 422, 'Hanya order allocated atau picking yang dapat dilepas.');
            foreach (json_decode($order->lines, true, flags: JSON_THROW_ON_ERROR) as $line) {
                $stock = DB::table('warehouse_stock')->where('id', $line['stock_id'])->where('company_id', $company->id)->lockForUpdate()->first();
                abort_unless($stock && (float) $stock->allocated >= (float) $line['quantity'], 409, 'Alokasi stok tidak lagi konsisten.');
                DB::table('warehouse_stock')->where('id', $stock->id)->decrement('allocated', $line['quantity'], ['updated_at' => now()]);
            }
            DB::table('warehouse_orders')->where('id', $id)->update(['status' => 'cancelled', 'updated_at' => now()]);

            return DB::table('warehouse_orders')->where('id', $id)->first();
        });

        return response()->json(['message' => 'Allocation released.', 'data' => $order]);
    }

    public function ship(ShipWarehouseOrderRequest $request, int $id)
    {
        $company = $this->context->tenant($request);
        $data = $request->validated();
        DB::transaction(function () use ($company, $id, $data, $request) {
            $order = DB::table('warehouse_orders')->where('company_id', $company->id)->where('id', $id)->lockForUpdate()->first();
            abort_unless($order, 404);
            abort_unless($order->status === 'packed', 422, 'Order harus dikemas sebelum dikirim.');
            foreach (json_decode($order->lines, true) as $line) {
                $stock = DB::table('warehouse_stock')->where('id', $line['stock_id'])->lockForUpdate()->first();
                abort_unless($stock && $stock->on_hand >= $line['quantity'] && $stock->allocated >= $line['quantity'], 409, 'Stok berubah sejak order dialokasikan.');
                DB::table('warehouse_stock')->where('id', $stock->id)->decrement('on_hand', $line['quantity']);
                DB::table('warehouse_stock')->where('id', $stock->id)->decrement('allocated', $line['quantity']);
                DB::table('warehouse_transactions')->insert(['company_id' => $order->company_id, 'warehouse_id' => $order->warehouse_id, 'stock_id' => $stock->id, 'type' => 'shipment', 'quantity' => -$line['quantity'], 'reference' => $order->order_number, 'user_id' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            }
            DB::table('warehouse_orders')->where('id', $id)->update(['status' => 'shipped', 'carrier' => $data['carrier'] ?? null, 'tracking_number' => $data['tracking_number'] ?? null, 'shipped_at' => now(), 'updated_at' => now()]);
        });

        return response()->json(['message' => 'Order shipped.', 'data' => DB::table('warehouse_orders')->where('id', $id)->first()]);
    }

    public function orders(Request $request)
    {
        $company = $this->context->tenant($request);
        $query = DB::table('warehouse_orders as o')->join('warehouses as w', 'w.id', '=', 'o.warehouse_id')->where('o.company_id', $company->id)->select('o.*', 'w.name as warehouse_name');
        if ($request->filled('status')) {
            $query->where('o.status', $request->query('status'));
        }

        return response()->json(['data' => $query->orderByDesc('o.id')->paginate(50)]);
    }

    /**
     * Build a PostgreSQL-compatible ORDER BY CASE expression to preserve
     * a specific order of values (equivalent to MySQL's FIELD() function).
     *
     * @param  non-empty-string  $column
     * @param  array<int, string|int>  $values
     */
    private function buildOrderByCase(string $column, array $values): string
    {
        $cases = [];
        foreach ($values as $index => $value) {
            $cases[] = 'WHEN '.(is_int($value) ? $value : "'".DB::getPdo()->quote((string) $value)."'").' THEN '.$index;
        }

        return 'CASE '.$column.' '.implode(' ', $cases).' END';
    }
}
