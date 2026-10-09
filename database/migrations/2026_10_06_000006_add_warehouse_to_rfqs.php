<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('rfqs', 'warehouse_id')) {
            Schema::table('rfqs', function (Blueprint $table) {
                $table->foreignUuid('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('rfqs', 'warehouse_id')) {
            Schema::table('rfqs', function (Blueprint $table) {
                $table->dropForeign(['warehouse_id']);
                $table->dropColumn('warehouse_id');
            });
        }
    }
};
