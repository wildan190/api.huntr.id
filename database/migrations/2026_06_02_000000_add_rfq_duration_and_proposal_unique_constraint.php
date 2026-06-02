<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rfqs', function (Blueprint $table) {
            if (!Schema::hasColumn('rfqs', 'duration_days')) {
                $table->integer('duration_days')->default(7)->after('status');
            }
        });

        Schema::table('proposals', function (Blueprint $table) {
            if (Schema::hasColumn('proposals', 'rfq_id') && Schema::hasColumn('proposals', 'company_id')) {
                $table->unique(['rfq_id', 'company_id'], 'proposals_rfq_company_unique');
            }
        });
    }

    public function down(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            if (Schema::hasColumn('proposals', 'rfq_id') && Schema::hasColumn('proposals', 'company_id')) {
                $table->dropUnique('proposals_rfq_company_unique');
            }
        });

        Schema::table('rfqs', function (Blueprint $table) {
            if (Schema::hasColumn('rfqs', 'duration_days')) {
                $table->dropColumn('duration_days');
            }
        });
    }
};
