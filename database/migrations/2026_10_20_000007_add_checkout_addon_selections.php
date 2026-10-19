<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_checkouts', function (Blueprint $table) {
            $table->json('addons_snapshot')->nullable();
        });
        Schema::table('company_subscription_addons', function (Blueprint $table) {
            $table->unique(['subscription_checkout_id', 'addon_key'], 'subscription_checkout_addon_unique');
            $table->dropUnique('company_subscription_addons_subscription_checkout_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('company_subscription_addons', function (Blueprint $table) {
            $table->unique('subscription_checkout_id');
            $table->dropUnique('subscription_checkout_addon_unique');
        });
        Schema::table('subscription_checkouts', function (Blueprint $table) {
            $table->dropColumn('addons_snapshot');
        });
    }
};
