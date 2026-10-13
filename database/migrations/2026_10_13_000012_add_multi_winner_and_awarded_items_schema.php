<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Add is_awarded to proposal_items
        Schema::table('proposal_items', function (Blueprint $table) {
            $table->boolean('is_awarded')->default(false)->after('price_offer');
        });

        // 2. Add award_mode to rfqs
        Schema::table('rfqs', function (Blueprint $table) {
            $table->string('award_mode')->nullable()->default('single')->after('status');
        });

        // 3. Add awarded_amount to proposals
        Schema::table('proposals', function (Blueprint $table) {
            $table->decimal('awarded_amount', 15, 2)->nullable()->after('price_offer');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            $table->dropColumn('awarded_amount');
        });

        Schema::table('rfqs', function (Blueprint $table) {
            $table->dropColumn('award_mode');
        });

        Schema::table('proposal_items', function (Blueprint $table) {
            $table->dropColumn('is_awarded');
        });
    }
};
