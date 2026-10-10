<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('warehouses', 'capacity_units')) {
            Schema::table('warehouses', function (Blueprint $table) {
                $table->decimal('capacity_units', 16, 3)->nullable()->after('address');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('warehouses', 'capacity_units')) {
            Schema::table('warehouses', function (Blueprint $table) {
                $table->dropColumn('capacity_units');
            });
        }
    }
};
