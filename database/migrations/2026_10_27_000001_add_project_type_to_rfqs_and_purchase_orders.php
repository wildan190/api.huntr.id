<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rfqs', function (Blueprint $table) {
            $table->string('project_type', 100)->nullable()->after('procurement_type');
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->string('project_type', 100)->nullable()->after('procurement_type');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropColumn('project_type');
        });

        Schema::table('rfqs', function (Blueprint $table) {
            $table->dropColumn('project_type');
        });
    }
};
