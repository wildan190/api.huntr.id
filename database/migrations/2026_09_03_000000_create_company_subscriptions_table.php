<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->index();
            $table->string('plan', 32)->default('gmv_subscription');
            $table->string('status', 32)->default('active')->index();
            $table->string('overflow_strategy', 32)->default('transaction_fee');
            $table->decimal('upfront_fee', 15, 2);
            $table->decimal('gmv_limit', 15, 2);
            $table->decimal('current_realized_gmv', 15, 2)->default(0);
            $table->decimal('reserved_gmv', 15, 2)->default(0);
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->index();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_subscriptions');
    }
};
