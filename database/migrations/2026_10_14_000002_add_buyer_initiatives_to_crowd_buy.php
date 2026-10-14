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
        Schema::table('crowd_buy_campaigns', function (Blueprint $table) {
            $table->uuid('supplier_company_id')->nullable()->change();
            $table->string('creator_type')->default('supplier')->after('code'); // 'supplier' or 'buyer'
            $table->string('campaign_type')->default('supplier_promo')->after('creator_type'); // 'supplier_promo', 'buyer_catalog', 'collective_rfq'
            $table->uuid('initiator_company_id')->nullable()->after('supplier_company_id');
            $table->string('invite_code', 32)->nullable()->unique()->after('code');
            $table->decimal('target_price', 15, 2)->nullable()->after('base_price');

            $table->index('creator_type');
            $table->index('campaign_type');
            $table->index('initiator_company_id');
            $table->index('invite_code');
        });

        Schema::create('crowd_buy_bids', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('campaign_id');
            $table->uuid('supplier_company_id');
            $table->uuid('supplier_user_id');
            $table->decimal('offered_unit_price', 15, 2);
            $table->integer('min_order_qty')->default(1);
            $table->integer('lead_time_days')->nullable();
            $table->text('notes')->nullable();
            $table->string('status')->default('pending'); // pending, accepted, rejected, withdrawn
            $table->timestamps();

            $table->foreign('campaign_id')->references('id')->on('crowd_buy_campaigns')->onDelete('cascade');
            $table->foreign('supplier_company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->index(['campaign_id', 'status']);
            $table->index('supplier_company_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('crowd_buy_bids');

        Schema::table('crowd_buy_campaigns', function (Blueprint $table) {
            $table->dropIndex(['creator_type']);
            $table->dropIndex(['campaign_type']);
            $table->dropIndex(['initiator_company_id']);
            $table->dropIndex(['invite_code']);

            $table->dropColumn([
                'creator_type',
                'campaign_type',
                'initiator_company_id',
                'invite_code',
                'target_price',
            ]);

            $table->uuid('supplier_company_id')->nullable(false)->change();
        });
    }
};
