<?php

namespace App\Domain\Wms\Support;

use App\Domain\Catalogue\Models\Catalogue;
use App\Domain\Company\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WmsContext
{
    public function tenant(Request $request, bool $requireInstalled = true): Company
    {
        $user = $request->user();
        abort_unless($user, 401);
        $companyId = $request->input('company_id', $request->query('company_id'));
        $company = Company::query()->whereKey($companyId)->first();
        abort_unless($company && ((string) $company->owner_id === (string) $user->id || $company->users()->whereKey($user->id)->exists()), 403, 'Anda tidak memiliki akses ke perusahaan ini.');
        if ($requireInstalled) {
            abort_unless(DB::table('company_apps')->where('company_id', $company->id)->where('app_key', 'wms-inventory')->whereNotNull('installed_at')->exists(), 403, 'Install WMS & Inventory dari App Market terlebih dahulu.');
        }

        return $company;
    }

    public function getStock(Company $company, string $warehouseId, array $line, bool $create = false): object
    {
        abort_unless(DB::table('warehouses')->where('id', $warehouseId)->where('company_id', $company->id)->exists(), 404, 'Warehouse tidak ditemukan.');
        $skuRecord = $this->skuForTenant($company, $line, $create);
        $sku = $skuRecord->code;
        $catalogueId = $skuRecord->catalogue_id;
        $bin = $line['bin_location'] ?? null;
        $stockQuery = DB::table('warehouse_stock')->where('warehouse_id', $warehouseId)->where('wms_sku_id', $skuRecord->id);
        if ($bin !== null) {
            $stockQuery->where('bin_location', $bin);
        }
        $stock = $stockQuery->orderBy('id')->first();
        if (! $stock && $create) {
            $id = DB::table('warehouse_stock')->insertGetId(['company_id' => $company->id, 'warehouse_id' => $warehouseId, 'wms_sku_id' => $skuRecord->id, 'catalogue_id' => $catalogueId, 'sku' => $sku, 'item_name' => $skuRecord->name, 'uom' => $skuRecord->uom, 'bin_location' => $bin ?? 'RECEIVING', 'created_at' => now(), 'updated_at' => now()]);
            $stock = DB::table('warehouse_stock')->where('id', $id)->first();
        }
        abort_unless($stock, 404, 'SKU belum tercatat di gudang.');

        return $stock;
    }

    public function skuForTenant(Company $company, array $line, bool $create = false): object
    {
        $catalogue = ! empty($line['catalogue_id']) ? Catalogue::where('id', $line['catalogue_id'])->where(function ($query) use ($company) {
            $query->where('company_id', $company->id)->orWhereHas('company', fn ($vendor) => $vendor->where('type', 'vendor')->whereIn('status', ['approved', 'pending']));
        })->first() : null;
        if (! empty($line['catalogue_id'])) {
            abort_unless($catalogue, 422, 'Catalogue item tidak valid atau tidak tersedia.');
        }
        $sku = strtoupper(trim($line['sku'] ?? $catalogue?->item_code ?? ''));
        abort_unless($sku, 422, 'SKU atau catalogue_id wajib diisi.');
        $record = DB::table('wms_skus')->where('company_id', $company->id)->where('code', $sku)->first();
        if ($record && $catalogue && (string) $record->catalogue_id !== (string) $catalogue->id) {
            abort(422, 'SKU sudah terhubung ke item catalogue lain. Buat SKU unik untuk setiap varian.');
        }
        if (! $record && $create) {
            $id = (string) Str::uuid();
            DB::table('wms_skus')->insert(['id' => $id, 'company_id' => $company->id, 'catalogue_id' => $catalogue?->id, 'code' => $sku, 'name' => $line['item_name'] ?? $catalogue?->name ?? $sku, 'uom' => $line['uom'] ?? $catalogue?->uom ?? 'unit', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            $record = DB::table('wms_skus')->where('id', $id)->first();
        }
        abort_unless($record && $record->status === 'active', 404, 'SKU tidak aktif atau belum terdaftar.');

        return $record;
    }

    public function stockForTenant(Company $company, int $stockId): object
    {
        $stock = DB::table('warehouse_stock')->where('id', $stockId)->where('company_id', $company->id)->first();
        abort_unless($stock, 404, 'Stock item tidak ditemukan.');

        return $stock;
    }
}
