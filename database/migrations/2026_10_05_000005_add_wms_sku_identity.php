<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wms_skus', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('catalogue_id')->nullable()->constrained('catalogues')->nullOnDelete();
            $table->string('code', 100);
            $table->string('name');
            $table->string('uom', 30)->default('unit');
            $table->string('status', 32)->default('active');
            $table->timestamps();
            $table->unique(['company_id', 'code']);
            $table->unique(['company_id', 'catalogue_id']);
        });

        Schema::table('warehouse_stock', function (Blueprint $table) {
            $table->foreignUuid('wms_sku_id')->nullable()->constrained('wms_skus')->nullOnDelete();
            $table->index(['company_id', 'wms_sku_id']);
        });

        DB::table('warehouse_stock')->orderBy('id')->cursor()->each(function (object $stock): void {
            $baseCode = strtoupper(trim($stock->sku));
            $code = $baseCode;
            $existing = DB::table('wms_skus')->where('company_id', $stock->company_id)->where('code', $code)->first();
            if ($existing && (string) $existing->catalogue_id !== (string) $stock->catalogue_id) {
                $code = $baseCode.'-'.strtoupper(substr(str_replace('-', '', (string) ($stock->catalogue_id ?? $stock->id)), 0, 8));
                $existing = DB::table('wms_skus')->where('company_id', $stock->company_id)->where('code', $code)->first();
            }
            if (! $existing) {
                $skuId = (string) Str::uuid();
                DB::table('wms_skus')->insert([
                    'id' => $skuId, 'company_id' => $stock->company_id, 'catalogue_id' => $stock->catalogue_id,
                    'code' => $code, 'name' => $stock->item_name, 'uom' => $stock->uom ?? 'unit', 'status' => 'active',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            } else {
                $skuId = $existing->id;
            }
            DB::table('warehouse_stock')->where('id', $stock->id)->update(['wms_sku_id' => $skuId, 'sku' => $code, 'updated_at' => now()]);
        });
    }

    public function down(): void
    {
        Schema::table('warehouse_stock', function (Blueprint $table) {
            $table->dropForeign(['wms_sku_id']);
            $table->dropIndex(['company_id', 'wms_sku_id']);
            $table->dropColumn('wms_sku_id');
        });
        Schema::dropIfExists('wms_skus');
    }
};
