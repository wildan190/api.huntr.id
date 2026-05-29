<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * - Adds buyer_company_id to purchase_orders (nullable for historical imports)
     * - Relaxes rfq_id/vendor_id nullable for historical POs
     * - Creates historical_po_items table for line-level PO data from buyer imports
     */
    public function up(): void
    {
        // Add buyer_company_id and make rfq_id nullable on purchase_orders
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('buyer_company_id')->nullable()->after('id');
            $table->string('vendor_name')->nullable()->after('vendor_id'); // Raw vendor name for historical
            $table->string('department')->nullable()->after('vendor_name');
            $table->string('currency')->nullable()->after('department');
            $table->string('purchase_category')->nullable()->after('currency');
            $table->string('purchase_type')->nullable()->after('purchase_category');
            $table->date('order_date')->nullable()->after('purchase_type');
            $table->date('expected_receiving_date')->nullable()->after('order_date');
            $table->boolean('is_historical')->default(false)->after('status');
            $table->string('created_by')->nullable()->after('is_historical');
            $table->string('approved_by')->nullable()->after('created_by');

            // Make rfq_id nullable for historical imports (no actual RFQ was created)
            $table->unsignedBigInteger('rfq_id')->nullable()->change();
            $table->unsignedBigInteger('vendor_id')->nullable()->change();
            // Make po_number not unique to allow historical
            $table->string('po_number')->nullable()->change();
        });

        // Create historical_po_items table for line-level PO detail data
        Schema::create('historical_po_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('purchase_order_id');
            $table->string('pr_reference_number')->nullable();
            $table->string('inventory_code')->nullable();
            $table->string('inventory_name');
            $table->string('category')->nullable();
            $table->text('specifications')->nullable();
            $table->string('uom')->default('Pc');
            $table->decimal('qty', 15, 4)->default(1);
            $table->decimal('unit_price', 15, 2)->default(0);
            $table->decimal('amount', 15, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->string('currency')->default('IDR');
            $table->decimal('exchange_rate', 15, 6)->default(1);
            $table->string('clerk')->nullable();
            $table->string('created_by')->nullable();
            $table->string('approved_by')->nullable();
            $table->date('order_date')->nullable();
            $table->date('expected_receiving_date')->nullable();
            $table->timestamps();

            $table->foreign('purchase_order_id')->references('id')->on('purchase_orders')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('historical_po_items');

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropColumn([
                'buyer_company_id', 'vendor_name', 'department', 'currency',
                'purchase_category', 'purchase_type', 'order_date',
                'expected_receiving_date', 'is_historical', 'created_by', 'approved_by',
            ]);
        });
    }
};
