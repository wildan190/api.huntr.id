<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->uuid('company_subscription_id')->nullable()->index()->after('purchase_order_id');
            $table->string('billing_mode', 32)->default('transaction_fee')->after('status');
            $table->decimal('gmv_credited_amount', 15, 2)->default(0)->after('base_amount');
            $table->timestamp('gmv_consumed_at')->nullable()->after('gmv_credited_amount');

            $table->foreign('company_subscription_id')
                ->references('id')
                ->on('company_subscriptions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['company_subscription_id']);
            $table->dropColumn([
                'company_subscription_id',
                'billing_mode',
                'gmv_credited_amount',
                'gmv_consumed_at',
            ]);
        });
    }
};
