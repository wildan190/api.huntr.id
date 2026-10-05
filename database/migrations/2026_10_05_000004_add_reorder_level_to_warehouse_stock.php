<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('warehouse_stock', 'reorder_level')) {
            Schema::table('warehouse_stock', function (Blueprint $table) {
                $table->decimal('reorder_level', 16, 3)->default(0);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('warehouse_stock', 'reorder_level')) {
            Schema::table('warehouse_stock', function (Blueprint $table) {
                $table->dropColumn('reorder_level');
            });
        }
    }
};
