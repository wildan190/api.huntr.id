<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rfqs', function (Blueprint $table) {
            if (!Schema::hasColumn('rfqs', 'approved_by')) {
                $table->string('approved_by')->nullable()->after('status');
            }
            if (!Schema::hasColumn('rfqs', 'approved_at')) {
                $table->timestamp('approved_at')->nullable()->after('approved_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('rfqs', function (Blueprint $table) {
            if (Schema::hasColumn('rfqs', 'approved_at')) {
                $table->dropColumn('approved_at');
            }
            if (Schema::hasColumn('rfqs', 'approved_by')) {
                $table->dropColumn('approved_by');
            }
        });
    }
};
