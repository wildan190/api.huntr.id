<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_subscriptions', function (Blueprint $table) {
            $table->string('enterprise_plan', 32)->nullable();
            $table->string('billing_cycle', 16)->nullable();
        });

        Schema::create('subscription_checkouts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('company_subscription_id')->nullable()->constrained('company_subscriptions')->nullOnDelete();
            $table->string('order_id')->unique();
            $table->string('enterprise_plan', 32);
            $table->string('billing_cycle', 16);
            $table->unsignedBigInteger('amount');
            $table->string('payment_method', 32);
            $table->string('status', 32)->index();
            $table->json('payment_info')->nullable();
            $table->json('provider_response')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_checkouts');
        Schema::table('company_subscriptions', function (Blueprint $table) {
            $table->dropColumn(['enterprise_plan', 'billing_cycle']);
        });
    }
};
