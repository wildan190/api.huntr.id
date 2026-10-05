<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_apps', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('app_key');
            $table->timestamp('installed_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'app_key']);
        });
        Schema::create('warehouses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('address')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'status']);
        });
        Schema::create('warehouse_stock', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->foreignUuid('catalogue_id')->nullable()->constrained('catalogues')->nullOnDelete();
            $table->string('sku');
            $table->string('item_name');
            $table->string('uom')->default('unit');
            $table->decimal('on_hand', 16, 3)->default(0);
            $table->decimal('allocated', 16, 3)->default(0);
            $table->decimal('reorder_level', 16, 3)->default(0);
            $table->string('bin_location')->nullable();
            $table->timestamps();
            // One SKU can be stored in multiple bins in the same warehouse.
            $table->unique(['warehouse_id', 'sku', 'bin_location']);
            $table->index(['company_id', 'sku']);
        });
        Schema::create('warehouse_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->foreignId('stock_id')->constrained('warehouse_stock')->cascadeOnDelete();
            $table->foreignUuid('purchase_order_id')->nullable()->constrained('purchase_orders')->nullOnDelete();
            $table->string('type');
            $table->decimal('quantity', 16, 3);
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'type', 'created_at']);
        });
        Schema::create('warehouse_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->string('order_number');
            $table->string('status')->default('allocated');
            $table->json('lines');
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('packed_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->string('carrier')->nullable();
            $table->string('tracking_number')->nullable();
            $table->text('packing_notes')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'order_number']);
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_orders');
        Schema::dropIfExists('warehouse_transactions');
        Schema::dropIfExists('warehouse_stock');
        Schema::dropIfExists('warehouses');
        Schema::dropIfExists('company_apps');
    }
};
