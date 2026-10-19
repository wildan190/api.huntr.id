<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_subscriptions', fn (Blueprint $table) => $table->json('plan_snapshot')->nullable());
        Schema::table('subscription_checkouts', fn (Blueprint $table) => $table->json('item_snapshot')->nullable());
        Schema::create('enterprise_pricing_audits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('admin_id');
            $table->json('before');
            $table->json('after');
            $table->timestamps();
        });
        DB::table('admin_settings')->insertOrIgnore(['key' => 'enterprise_pricing', 'value' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $plans = [
            'enterprise_starter' => ['key' => 'enterprise_starter', 'name' => 'Enterprise Starter', 'monthly_price' => 3800000, 'annual_price' => 30000000, 'users' => 15, 'tax_documents_per_year' => 1000, 'support' => 'Business Hours', 'warehouses' => 1],
            'enterprise_growth' => ['key' => 'enterprise_growth', 'name' => 'Enterprise Growth', 'monthly_price' => 6200000, 'annual_price' => 48947368, 'users' => 35, 'tax_documents_per_year' => 3000, 'support' => 'Priority', 'warehouses' => 2],
            'enterprise_pro' => ['key' => 'enterprise_pro', 'name' => 'Enterprise Pro', 'monthly_price' => 9500000, 'annual_price' => 75000000, 'users' => 70, 'tax_documents_per_year' => 5000, 'support' => 'Priority', 'warehouses' => 10],
        ];
        foreach ($plans as $key => $plan) {
            DB::table('company_subscriptions')->where('enterprise_plan', $key)->whereNull('plan_snapshot')->update(['plan_snapshot' => json_encode($plan)]);
            DB::table('subscription_checkouts')->where('enterprise_plan', $key)->where('purchase_type', 'plan')->whereNull('item_snapshot')->update(['item_snapshot' => json_encode($plan)]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_pricing_audits');
        Schema::table('subscription_checkouts', fn (Blueprint $table) => $table->dropColumn('item_snapshot'));
        Schema::table('company_subscriptions', fn (Blueprint $table) => $table->dropColumn('plan_snapshot'));
    }
};
