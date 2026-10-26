<?php

namespace App\Domain\Wms\Services;

use App\Domain\Wms\Http\Requests\ImportWarehouseStockRequest;
use App\Domain\Wms\Support\WmsContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Imports a normalized stock snapshot from any WMS CSV/XLSX export. */
class WmsUniversalImportService
{
    public function __construct(private WmsContext $context) {}

    public function import(ImportWarehouseStockRequest $request)
    {
        $company = $this->context->tenant($request);
        $this->context->assertPermission($request, $company, 'wms.warehouse.manage');
        $data = $request->validated();
        $warehouse = DB::table('warehouses')
            ->where('id', $data['warehouse_id'])
            ->where('company_id', $company->id)
            ->where('status', 'active')
            ->first();
        abort_unless($warehouse, 404, 'Warehouse aktif tidak ditemukan.');

        $result = DB::transaction(function () use ($company, $warehouse, $data, $request) {
            $existing = DB::table('warehouse_stock_imports')
                ->where('company_id', $company->id)
                ->where('idempotency_key', $data['idempotency_key'])
                ->lockForUpdate()
                ->first();
            if ($existing) return ['import' => $existing, 'replayed' => true];

            $importId = (string) Str::uuid();
            $reference = 'IMP-'.now()->format('Ymd').'-'.Str::upper(Str::random(8));
            $summary = ['processed' => 0, 'created_skus' => 0, 'created_bins' => 0, 'updated_stock' => 0, 'quantity_delta' => 0.0];
            $bins = DB::table('warehouse_bins')
                ->where('company_id', $company->id)
                ->where('warehouse_id', $warehouse->id)
                ->where('status', 'active')
                ->get()
                ->keyBy(fn (object $bin) => strtoupper($bin->code));
            $fallbackBin = $bins->firstWhere('type', 'storage') ?? $bins->firstWhere('type', 'receiving');
            abort_unless($fallbackBin, 422, 'Warehouse tidak memiliki bin aktif untuk menampung hasil import.');

            foreach ($this->consolidateLines($data['lines']) as $index => $line) {
                $sku = strtoupper(trim($line['sku']));
                $itemName = trim($line['item_name']);
                $quantity = round((float) $line['quantity'], 3);
                $binCode = strtoupper(trim((string) ($line['bin_code'] ?? '')));
                $bin = $binCode !== '' ? $bins->get($binCode) : $fallbackBin;

                if (! $bin && ($data['create_missing_bins'] ?? false)) {
                    abort_unless(preg_match('/^[A-Z0-9][A-Z0-9._\/-]{0,79}$/', $binCode) === 1, 422, 'Kode bin pada baris '.($line['source_row'] ?? $index + 2).' tidak valid.');
                    $bin = (object) ['id' => (string) Str::uuid(), 'code' => $binCode, 'type' => 'storage'];
                    DB::table('warehouse_bins')->insert([
                        'id' => $bin->id, 'company_id' => $company->id, 'warehouse_id' => $warehouse->id,
                        'code' => $bin->code, 'name' => "Imported {$bin->code}", 'type' => 'storage',
                        'status' => 'active', 'is_system' => false, 'pick_priority' => 100,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $bins->put($bin->code, $bin);
                    $summary['created_bins']++;
                }
                abort_unless($bin, 422, "Bin {$binCode} pada baris ".($line['source_row'] ?? $index + 2).' tidak ditemukan. Aktifkan opsi buat bin baru atau perbaiki file.');

                $wasKnown = DB::table('wms_skus')->where('company_id', $company->id)->where('code', $sku)->exists();
                $stock = $this->context->getStock($company, $warehouse->id, [
                    'sku' => $sku,
                    'item_name' => $itemName,
                    'uom' => trim((string) ($line['uom'] ?? '')) ?: 'unit',
                    'bin_location' => $bin->code,
                ], true);
                if (! $wasKnown) $summary['created_skus']++;

                $stock = DB::table('warehouse_stock')->where('id', $stock->id)->lockForUpdate()->first();
                $currentOnHand = (float) $stock->on_hand;
                $nextOnHand = $data['mode'] === 'add' ? round($currentOnHand + $quantity, 3) : $quantity;
                abort_if($nextOnHand + 0.0005 < (float) $stock->allocated, 422, "Stok impor {$sku} di {$bin->code} lebih kecil dari stok yang sudah dialokasikan.");

                $updates = ['bin_id' => $bin->id, 'bin_location' => $bin->code, 'on_hand' => $nextOnHand, 'updated_at' => now()];
                if (array_key_exists('unit_cost', $line) && $line['unit_cost'] !== null) $updates['average_unit_cost'] = $line['unit_cost'];
                if (array_key_exists('reorder_level', $line) && $line['reorder_level'] !== null) $updates['reorder_level'] = $line['reorder_level'];
                DB::table('warehouse_stock')->where('id', $stock->id)->update($updates);

                $delta = round($nextOnHand - $currentOnHand, 3);
                if (abs($delta) > 0.0005) {
                    DB::table('warehouse_transactions')->insert([
                        'company_id' => $company->id, 'warehouse_id' => $warehouse->id, 'stock_id' => $stock->id,
                        'to_bin_id' => $bin->id, 'type' => 'import', 'quantity' => $delta, 'reference' => $reference,
                        'notes' => 'Universal WMS import ('.$data['mode'].')'.(! empty($data['source_name']) ? ': '.$data['source_name'] : ''),
                        'user_id' => $request->user()->id, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $summary['quantity_delta'] += $delta;
                }
                $summary['processed']++;
                $summary['updated_stock']++;
            }

            DB::table('warehouse_stock_imports')->insert([
                'id' => $importId, 'company_id' => $company->id, 'warehouse_id' => $warehouse->id,
                'created_by' => $request->user()->id, 'idempotency_key' => $data['idempotency_key'],
                'reference' => $reference, 'source_name' => $data['source_name'] ?? null,
                'file_name' => $data['file_name'] ?? null, 'mode' => $data['mode'],
                'mapping' => isset($data['mapping']) ? json_encode($data['mapping']) : null,
                'summary' => json_encode($summary), 'created_at' => now(), 'updated_at' => now(),
            ]);

            return ['import' => DB::table('warehouse_stock_imports')->where('id', $importId)->first(), 'replayed' => false, 'summary' => $summary];
        });

        return response()->json([
            'data' => $result['import'],
            'summary' => $result['summary'] ?? json_decode($result['import']->summary ?? '{}', true),
            'replayed' => $result['replayed'],
        ], $result['replayed'] ? 200 : 201);
    }

    /** Consolidates split lot rows into one stock figure for each SKU and bin. */
    private function consolidateLines(array $lines): array
    {
        return collect($lines)
            ->groupBy(fn (array $line) => strtoupper(trim($line['sku'])).'|'.strtoupper(trim((string) ($line['bin_code'] ?? ''))))
            ->map(function ($group) {
                $line = $group->first();
                $line['quantity'] = round($group->sum(fn (array $item) => (float) $item['quantity']), 3);
                $line['source_row'] = $group->min('source_row');

                return $line;
            })
            ->values()
            ->all();
    }
}
