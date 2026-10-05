<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_apps', function (Blueprint $table) {
            $table->string('plan', 32)->default('starter');
            $table->timestamp('trial_ends_at')->nullable();
            $table->json('entitlements')->nullable();
        });

        Schema::table('warehouse_stock', function (Blueprint $table) {
            $table->decimal('average_unit_cost', 18, 4)->nullable();
        });

        Schema::table('warehouse_receipts', function (Blueprint $table) {
            $table->foreignUuid('goods_receipt_id')->nullable()->constrained('goods_receipts')->nullOnDelete()->unique();
        });

        Schema::table('warehouse_receipt_lines', function (Blueprint $table) {
            $table->string('lot_number', 120)->nullable();
            $table->string('serial_number', 160)->nullable();
            $table->date('expiry_date')->nullable();
            $table->decimal('unit_cost', 18, 4)->nullable();
        });

        Schema::create('warehouse_lots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->foreignId('stock_id')->constrained('warehouse_stock')->cascadeOnDelete();
            $table->foreignUuid('bin_id')->nullable()->constrained('warehouse_bins')->nullOnDelete();
            $table->foreignUuid('catalogue_id')->nullable()->constrained('catalogues')->nullOnDelete();
            $table->string('sku', 100);
            $table->string('lot_number', 120)->nullable();
            $table->string('serial_number', 160)->nullable();
            $table->date('expiry_date')->nullable();
            $table->decimal('on_hand', 16, 3)->default(0);
            $table->decimal('allocated', 16, 3)->default(0);
            $table->decimal('unit_cost', 18, 4)->nullable();
            $table->string('status', 32)->default('available');
            $table->timestamp('received_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'warehouse_id', 'sku', 'status']);
            $table->index(['company_id', 'expiry_date']);
            $table->unique(['company_id', 'serial_number']);
        });

        Schema::create('warehouse_pick_waves', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->string('wave_number', 100);
            $table->string('status', 32)->default('open');
            $table->foreignUuid('assigned_picker_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'wave_number']);
        });

        Schema::create('warehouse_pick_tasks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('wave_id')->constrained('warehouse_pick_waves')->cascadeOnDelete();
            $table->foreignId('warehouse_order_id')->constrained('warehouse_orders')->cascadeOnDelete();
            $table->foreignId('stock_id')->constrained('warehouse_stock')->cascadeOnDelete();
            $table->foreignUuid('lot_id')->nullable()->constrained('warehouse_lots')->nullOnDelete();
            $table->decimal('quantity', 16, 3);
            $table->decimal('picked_quantity', 16, 3)->default(0);
            $table->string('barcode', 160)->nullable();
            $table->string('status', 32)->default('open');
            $table->timestamp('verified_at')->nullable();
            $table->foreignUuid('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['wave_id', 'status']);
        });

        Schema::create('warehouse_cycle_counts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->string('count_number', 100);
            $table->string('status', 32)->default('draft');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('counted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'count_number']);
        });

        Schema::create('warehouse_cycle_count_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('cycle_count_id')->constrained('warehouse_cycle_counts')->cascadeOnDelete();
            $table->foreignId('stock_id')->constrained('warehouse_stock')->cascadeOnDelete();
            $table->decimal('system_quantity', 16, 3);
            $table->decimal('counted_quantity', 16, 3)->nullable();
            $table->decimal('variance_quantity', 16, 3)->nullable();
            $table->text('variance_reason')->nullable();
            $table->timestamps();
            $table->unique(['cycle_count_id', 'stock_id']);
        });

        Schema::create('warehouse_transfers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('from_warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->foreignUuid('to_warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->string('transfer_number', 100);
            $table->string('status', 32)->default('requested');
            $table->foreignUuid('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('shipped_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'transfer_number']);
        });

        Schema::create('warehouse_transfer_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('transfer_id')->constrained('warehouse_transfers')->cascadeOnDelete();
            $table->foreignId('source_stock_id')->constrained('warehouse_stock')->cascadeOnDelete();
            $table->foreignId('destination_stock_id')->nullable()->constrained('warehouse_stock')->nullOnDelete();
            $table->decimal('quantity', 16, 3);
            $table->timestamps();
        });

        Schema::create('warehouse_rmas', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->string('rma_number', 100);
            $table->string('type', 32);
            $table->string('status', 32)->default('open');
            $table->string('reference', 160)->nullable();
            $table->text('reason')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'rma_number']);
        });

        Schema::create('warehouse_rma_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('rma_id')->constrained('warehouse_rmas')->cascadeOnDelete();
            $table->foreignId('stock_id')->nullable()->constrained('warehouse_stock')->nullOnDelete();
            $table->string('sku', 100);
            $table->decimal('quantity', 16, 3);
            $table->string('disposition', 32)->default('quarantine');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('warehouse_carriers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('code', 80);
            $table->string('tracking_url_template')->nullable();
            $table->json('credentials')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'code']);
        });

        Schema::create('warehouse_webhooks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('event', 100);
            $table->string('url', 2048);
            $table->string('secret', 160);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['company_id', 'event', 'is_active']);
        });

        Schema::create('warehouse_settings', function (Blueprint $table) {
            $table->foreignUuid('company_id')->primary()->constrained('companies')->cascadeOnDelete();
            $table->boolean('auto_create_purchase_suggestions')->default(true);
            $table->boolean('low_stock_notifications')->default(true);
            $table->json('notification_channels')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_settings');
        Schema::dropIfExists('warehouse_webhooks');
        Schema::dropIfExists('warehouse_carriers');
        Schema::dropIfExists('warehouse_rma_lines');
        Schema::dropIfExists('warehouse_rmas');
        Schema::dropIfExists('warehouse_transfer_lines');
        Schema::dropIfExists('warehouse_transfers');
        Schema::dropIfExists('warehouse_cycle_count_lines');
        Schema::dropIfExists('warehouse_cycle_counts');
        Schema::dropIfExists('warehouse_pick_tasks');
        Schema::dropIfExists('warehouse_pick_waves');
        Schema::dropIfExists('warehouse_lots');
        Schema::table('warehouse_receipt_lines', function (Blueprint $table) {
            $table->dropColumn(['lot_number', 'serial_number', 'expiry_date', 'unit_cost']);
        });
        Schema::table('warehouse_receipts', function (Blueprint $table) {
            $table->dropForeign(['goods_receipt_id']);
            $table->dropUnique(['goods_receipt_id']);
            $table->dropColumn('goods_receipt_id');
        });
        Schema::table('warehouse_stock', function (Blueprint $table) {
            $table->dropColumn('average_unit_cost');
        });
        Schema::table('company_apps', function (Blueprint $table) {
            $table->dropColumn(['plan', 'trial_ends_at', 'entitlements']);
        });
    }
};
