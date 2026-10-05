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
        Schema::create('warehouse_bins', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->string('code', 80);
            $table->string('name', 160);
            $table->string('type', 32)->default('storage');
            $table->string('status', 24)->default('active');
            $table->boolean('is_system')->default(false);
            $table->unsignedInteger('pick_priority')->default(100);
            $table->decimal('capacity_units', 16, 3)->nullable();
            $table->timestamps();
            $table->unique(['warehouse_id', 'code']);
            $table->index(['company_id', 'warehouse_id', 'type', 'status']);
        });

        DB::table('warehouses')->orderBy('id')->cursor()->each(function (object $warehouse): void {
            foreach ([['RECEIVING', 'Receiving', 'receiving'], ['STORAGE', 'Storage', 'storage'], ['QUARANTINE', 'Quarantine', 'quarantine']] as [$code, $name, $type]) {
                DB::table('warehouse_bins')->insert([
                    'id' => (string) Str::uuid(),
                    'company_id' => $warehouse->company_id,
                    'warehouse_id' => $warehouse->id,
                    'code' => $code,
                    'name' => $name,
                    'type' => $type,
                    'status' => 'active',
                    'is_system' => true,
                    'pick_priority' => 100,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        Schema::table('warehouse_stock', function (Blueprint $table) {
            $table->foreignUuid('bin_id')->nullable()->constrained('warehouse_bins')->nullOnDelete();
            $table->index(['company_id', 'warehouse_id', 'bin_id']);
        });

        Schema::create('warehouse_receipts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->foreignUuid('purchase_order_id')->nullable()->constrained('purchase_orders')->nullOnDelete();
            $table->foreignUuid('receiving_bin_id')->nullable()->constrained('warehouse_bins')->nullOnDelete();
            $table->foreignUuid('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('receipt_number', 120);
            $table->uuid('idempotency_key');
            $table->string('status', 32)->default('received');
            $table->string('reference', 160)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('received_at');
            $table->timestamps();
            $table->unique(['company_id', 'receipt_number']);
            $table->unique(['company_id', 'idempotency_key']);
            $table->index(['company_id', 'status', 'received_at']);
        });

        Schema::create('warehouse_receipt_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('receipt_id')->constrained('warehouse_receipts')->cascadeOnDelete();
            $table->foreignId('stock_id')->nullable()->constrained('warehouse_stock')->nullOnDelete();
            $table->foreignUuid('catalogue_id')->nullable()->constrained('catalogues')->nullOnDelete();
            $table->string('sku', 100);
            $table->string('item_name');
            $table->string('uom', 30)->default('unit');
            $table->decimal('ordered_quantity', 16, 3)->nullable();
            $table->decimal('received_quantity', 16, 3);
            $table->decimal('accepted_quantity', 16, 3);
            $table->decimal('rejected_quantity', 16, 3)->default(0);
            $table->string('condition', 32)->default('good');
            $table->text('inspection_notes')->nullable();
            $table->timestamps();
            $table->index(['receipt_id', 'sku']);
        });

        Schema::table('warehouse_transactions', function (Blueprint $table) {
            $table->foreignUuid('receipt_id')->nullable()->constrained('warehouse_receipts')->nullOnDelete();
            $table->foreignUuid('from_bin_id')->nullable()->constrained('warehouse_bins')->nullOnDelete();
            $table->foreignUuid('to_bin_id')->nullable()->constrained('warehouse_bins')->nullOnDelete();
            $table->index(['company_id', 'receipt_id']);
        });
    }

    public function down(): void
    {
        Schema::table('warehouse_transactions', function (Blueprint $table) {
            $table->dropForeign(['receipt_id']);
            $table->dropForeign(['from_bin_id']);
            $table->dropForeign(['to_bin_id']);
            $table->dropIndex(['company_id', 'receipt_id']);
            $table->dropColumn(['receipt_id', 'from_bin_id', 'to_bin_id']);
        });
        Schema::dropIfExists('warehouse_receipt_lines');
        Schema::dropIfExists('warehouse_receipts');
        Schema::table('warehouse_stock', function (Blueprint $table) {
            $table->dropForeign(['bin_id']);
            $table->dropIndex(['company_id', 'warehouse_id', 'bin_id']);
            $table->dropColumn('bin_id');
        });
        Schema::dropIfExists('warehouse_bins');
    }
};
