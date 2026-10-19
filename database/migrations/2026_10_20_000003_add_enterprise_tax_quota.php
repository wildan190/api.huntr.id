<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_tax_quota_periods', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->timestamps();
            $table->unique(['company_id', 'starts_at']);
        });

        Schema::table('company_subscription_addons', function (Blueprint $table) {
            $table->foreignUuid('tax_quota_period_id')->nullable()->constrained('company_tax_quota_periods')->nullOnDelete();
        });

        Schema::create('company_tax_quota_usage', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('tax_quota_period_id')->constrained('company_tax_quota_periods')->cascadeOnDelete();
            $table->string('bast_id')->unique();
            $table->string('status', 16);
            $table->uuid('efaktur_id')->nullable();
            $table->timestamps();
            $table->index(['tax_quota_period_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_tax_quota_usage');
        Schema::table('company_subscription_addons', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tax_quota_period_id');
        });
        Schema::dropIfExists('company_tax_quota_periods');
    }
};
