<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('warehouse_stock', 'lead_time_days')) {
            Schema::table('warehouse_stock', function (Blueprint $table) {
                $table->integer('lead_time_days')->default(0)->after('reorder_level');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('warehouse_stock', 'lead_time_days')) {
            Schema::table('warehouse_stock', function (Blueprint $table) {
                $table->dropColumn('lead_time_days');
            });
        }
    }
};
