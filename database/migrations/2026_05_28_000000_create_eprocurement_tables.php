<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Add fields to users table (if they don't exist yet)
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'role')) {
                $table->string('role')->default('buyer'); // admin, buyer, vendor, manager, finance
            }
            if (!Schema::hasColumn('users', 'company_id')) {
                $table->uuid('company_id')->nullable();
            }
            if (!Schema::hasColumn('users', 'whatsapp')) {
                $table->string('whatsapp')->nullable()->unique();
            }
        });

        // 2. Companies table
        Schema::create('companies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('type'); // buyer, vendor
            $table->string('status')->default('pending'); // pending, approved, rejected
            $table->text('verification_notes')->nullable();
            $table->timestamps();
        });

        // 3. Catalogues table
        Schema::create('catalogues', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id'); // belonging to a company (vendor's inventory or buyer's standard item list)
            $table->string('item_code');
            $table->string('name');
            $table->string('category')->nullable();
            $table->text('specifications')->nullable();
            $table->string('uom')->default('Pc');
            $table->decimal('price', 15, 2)->default(0.00);
            $table->timestamps();
        });

        // 4. RFQs table
        Schema::create('rfqs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id'); // buyer company
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status')->default('draft'); // draft, pending_manager, active, awarded, closed
            $table->timestamps();
        });

        // 5. RFQ Items table
        Schema::create('rfq_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('rfq_id');
            $table->uuid('catalogue_id');
            $table->integer('qty');
            $table->date('expected_date')->nullable();
            $table->timestamps();
        });

        // 6. Proposals (Tenders) table
        Schema::create('proposals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('rfq_id');
            $table->uuid('company_id'); // vendor company
            $table->decimal('price_offer', 15, 2);
            $table->integer('delivery_days');
            $table->integer('warranty_months')->default(12);
            $table->string('status')->default('submitted'); // submitted, accepted, rejected
            $table->timestamps();
        });

        // 7. Purchase Orders table
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('rfq_id');
            $table->uuid('vendor_id'); // vendor company
            $table->string('po_number')->unique();
            $table->string('status')->default('pending_manager'); // pending_manager, approved, confirmed, paid, shipping, completed
            $table->timestamps();
        });

        // 8. Invoices table
        Schema::create('invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('purchase_order_id');
            $table->string('type')->default('proforma'); // proforma, final
            $table->decimal('amount', 15, 2);
            $table->string('status')->default('unpaid'); // unpaid, paid, pending_finance
            $table->timestamps();
        });

        // 9. Delivery Orders table
        Schema::create('delivery_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('purchase_order_id');
            $table->string('do_number')->unique();
            $table->string('status')->default('shipped'); // shipped, delivered, received
            $table->timestamps();
        });

        // 10. Goods Receipts table
        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('delivery_order_id');
            $table->integer('received_qty');
            $table->string('handover_document_path')->nullable();
            $table->string('status')->default('completed');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('goods_receipts');
        Schema::dropIfExists('delivery_orders');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('proposals');
        Schema::dropIfExists('rfq_items');
        Schema::dropIfExists('rfqs');
        Schema::dropIfExists('catalogues');
        Schema::dropIfExists('companies');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'company_id', 'whatsapp']);
        });
    }
};
