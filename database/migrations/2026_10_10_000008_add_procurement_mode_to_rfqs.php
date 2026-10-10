<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('rfqs', 'procurement_mode')) {
            Schema::table('rfqs', function (Blueprint $table) {
                $table->string('procurement_mode', 24)->default('tender')->after('status');
                $table->index(['company_id', 'procurement_mode', 'status'], 'rfqs_company_mode_status_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('rfqs', 'procurement_mode')) {
            Schema::table('rfqs', function (Blueprint $table) {
                $table->dropIndex('rfqs_company_mode_status_index');
                $table->dropColumn('procurement_mode');
            });
        }
    }
};
