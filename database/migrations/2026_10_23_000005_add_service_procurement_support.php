<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rfqs', function (Blueprint $table) {
            $table->string('procurement_type', 16)->default('goods')->after('procurement_mode')->index();
            $table->date('service_start_date')->nullable()->after('warehouse_id');
            $table->date('service_end_date')->nullable()->after('service_start_date');
            $table->string('service_location')->nullable()->after('service_end_date');
            $table->text('acceptance_criteria')->nullable()->after('service_location');
        });
        Schema::table('rfq_items', function (Blueprint $table) {
            $table->string('line_type', 16)->default('goods')->after('rfq_id')->index();
            $table->text('service_scope')->nullable()->after('uom');
            $table->text('acceptance_criteria')->nullable()->after('service_scope');
            $table->date('service_start_date')->nullable()->after('acceptance_criteria');
            $table->date('service_end_date')->nullable()->after('service_start_date');
        });
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->string('procurement_type', 16)->default('goods')->after('rfq_id')->index();
            $table->date('service_start_date')->nullable()->after('expected_receiving_date');
            $table->date('service_end_date')->nullable()->after('service_start_date');
            $table->string('service_location')->nullable()->after('service_end_date');
            $table->text('acceptance_criteria')->nullable()->after('service_location');
            $table->boolean('requires_service_acceptance')->default(false)->after('acceptance_criteria');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', fn (Blueprint $table) => $table->dropColumn(['procurement_type', 'service_start_date', 'service_end_date', 'service_location', 'acceptance_criteria', 'requires_service_acceptance']));
        Schema::table('rfq_items', fn (Blueprint $table) => $table->dropColumn(['line_type', 'service_scope', 'acceptance_criteria', 'service_start_date', 'service_end_date']));
        Schema::table('rfqs', fn (Blueprint $table) => $table->dropColumn(['procurement_type', 'service_start_date', 'service_end_date', 'service_location', 'acceptance_criteria']));
    }
};
