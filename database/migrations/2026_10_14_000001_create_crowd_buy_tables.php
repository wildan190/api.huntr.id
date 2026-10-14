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
        // 1. Crowd Buy Campaigns
        Schema::create('crowd_buy_campaigns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code')->unique(); // e.g., CBC-20261014-XXXX
            $table->uuid('supplier_company_id');
            $table->uuid('created_by');
            $table->uuid('catalogue_id')->nullable();

            $table->string('title');
            $table->text('description')->nullable();
            $table->string('category')->default('General');
            $table->string('unit')->default('pcs'); // pcs, box, kg, etc.
            $table->decimal('base_price', 15, 2);
            $table->integer('target_quantity');
            $table->integer('max_quantity')->nullable();
            $table->integer('min_order_qty')->default(1);
            $table->integer('max_order_qty')->nullable();
            $table->integer('current_quantity')->default(0);

            // States: draft, pending_review, scheduled, active, suspended, successful, failed, processing, completed, cancelled
            $table->string('status')->default('draft');

            $table->timestamp('starts_at');
            $table->timestamp('ends_at');

            $table->integer('lead_time_days')->nullable();
            $table->text('delivery_terms')->nullable();
            $table->text('return_policy')->nullable();
            $table->string('image_path')->nullable();

            // Admin Review
            $table->uuid('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();

            $table->timestamps();

            // Indexes
            $table->index(['status', 'ends_at']);
            $table->index('supplier_company_id');
            $table->index('created_by');
        });

        // 2. Crowd Buy Price Tiers (Volume-based bulk discounts)
        Schema::create('crowd_buy_price_tiers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('campaign_id');
            $table->integer('min_quantity');
            $table->integer('max_quantity')->nullable();
            $table->decimal('unit_price', 15, 2);
            $table->timestamps();

            $table->foreign('campaign_id')->references('id')->on('crowd_buy_campaigns')->onDelete('cascade');
            $table->index('campaign_id');
        });

        // 3. Crowd Buy Orders (Buyer participation)
        Schema::create('crowd_buy_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('order_number')->unique(); // e.g., CBO-20261014-XXXX
            $table->uuid('campaign_id');
            $table->uuid('buyer_company_id');
            $table->uuid('user_id');

            $table->integer('quantity');
            $table->decimal('unit_price', 15, 2);
            $table->decimal('subtotal', 15, 2);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('shipping_cost', 15, 2)->default(0);
            $table->decimal('total_amount', 15, 2);

            $table->json('shipping_address');

            // States: pending_payment, paid, processing, shipped, delivered, cancelled, refund_pending, refunded, disputed
            $table->string('status')->default('pending_payment');

            $table->timestamp('payment_deadline')->nullable();
            $table->timestamp('paid_at')->nullable();

            // Fulfillment / Shipment
            $table->string('carrier')->nullable();
            $table->string('tracking_number')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();

            $table->text('notes')->nullable();
            $table->text('cancellation_reason')->nullable();

            $table->timestamps();

            $table->foreign('campaign_id')->references('id')->on('crowd_buy_campaigns')->onDelete('restrict');
            $table->index(['campaign_id', 'status']);
            $table->index('buyer_company_id');
            $table->index('user_id');
        });

        // 4. Crowd Buy Payments (Linkage with payment provider / Midtrans)
        Schema::create('crowd_buy_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('crowd_buy_order_id');
            $table->string('external_id')->unique();
            $table->string('transaction_id')->nullable();
            $table->decimal('amount', 15, 2);
            $table->string('payment_type')->nullable();
            $table->string('payment_method')->nullable();
            $table->string('status')->default('pending'); // pending, paid, failed, expired, cancelled
            $table->json('payment_info')->nullable();
            $table->json('raw_response')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->foreign('crowd_buy_order_id')->references('id')->on('crowd_buy_orders')->onDelete('cascade');
            $table->index('crowd_buy_order_id');
        });

        // 5. Crowd Buy Campaign Audit / Status History
        Schema::create('crowd_buy_campaign_histories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('campaign_id');
            $table->uuid('actor_id')->nullable();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('campaign_id')->references('id')->on('crowd_buy_campaigns')->onDelete('cascade');
            $table->index('campaign_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('crowd_buy_campaign_histories');
        Schema::dropIfExists('crowd_buy_payments');
        Schema::dropIfExists('crowd_buy_orders');
        Schema::dropIfExists('crowd_buy_price_tiers');
        Schema::dropIfExists('crowd_buy_campaigns');
    }
};
