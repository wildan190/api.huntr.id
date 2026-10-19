<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_checkouts', function (Blueprint $table) {
            $table->string('purchase_type', 16)->default('plan');
            $table->string('addon_key', 32)->nullable();
            $table->unsignedInteger('quantity')->default(1);
        });

        Schema::create('company_subscription_addons', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('subscription_checkout_id')->unique()->constrained('subscription_checkouts')->cascadeOnDelete();
            $table->string('addon_key', 32);
            $table->unsignedInteger('quantity');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'addon_key', 'starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_subscription_addons');
        Schema::table('subscription_checkouts', function (Blueprint $table) {
            $table->dropColumn(['purchase_type', 'addon_key', 'quantity']);
        });
    }
};
