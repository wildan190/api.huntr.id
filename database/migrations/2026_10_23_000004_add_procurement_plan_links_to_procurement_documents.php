<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rfqs', function (Blueprint $table) {
            $table->uuid('procurement_plan_id')->nullable()->index()->after('company_id');
        });
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->uuid('procurement_plan_id')->nullable()->index()->after('rfq_id');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', fn (Blueprint $table) => $table->dropColumn('procurement_plan_id'));
        Schema::table('rfqs', fn (Blueprint $table) => $table->dropColumn('procurement_plan_id'));
    }
};
