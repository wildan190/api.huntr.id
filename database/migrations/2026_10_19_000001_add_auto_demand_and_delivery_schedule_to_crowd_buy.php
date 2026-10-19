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
            $table->boolean('is_monthly_recurring')->default(false)->after('campaign_type');
            $table->unsignedTinyInteger('monthly_delivery_day')->default(27)->after('is_monthly_recurring');
            $table->json('historical_metrics')->nullable()->after('monthly_delivery_day');

            $table->index('is_monthly_recurring');
        });

        Schema::table('crowd_buy_orders', function (Blueprint $table) {
            $table->boolean('is_monthly_commitment')->default(false)->after('quantity');
            $table->integer('monthly_commitment_qty')->nullable()->after('is_monthly_commitment');
            $table->string('delivery_point')->nullable()->after('shipping_address');

            $table->index('is_monthly_commitment');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('crowd_buy_campaigns', function (Blueprint $table) {
            $table->dropIndex(['is_monthly_recurring']);
            $table->dropColumn([
                'is_monthly_recurring',
                'monthly_delivery_day',
                'historical_metrics',
            ]);
        });

        Schema::table('crowd_buy_orders', function (Blueprint $table) {
            $table->dropIndex(['is_monthly_commitment']);
            $table->dropColumn([
                'is_monthly_commitment',
                'monthly_commitment_qty',
                'delivery_point',
            ]);
        });
    }
};
