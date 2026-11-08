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
        // 1. Add cancel award audit columns to rfqs
        Schema::table('rfqs', function (Blueprint $table) {
            $table->timestamp('award_cancelled_at')->nullable()->after('award_mode');
            $table->uuid('award_cancelled_by_user_id')->nullable()->after('award_cancelled_at');
            $table->text('award_cancellation_reason')->nullable()->after('award_cancelled_by_user_id');
        });

        // 2. Add quotation_number to proposals
        Schema::table('proposals', function (Blueprint $table) {
            $table->string('quotation_number')->nullable()->after('price_offer');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rfqs', function (Blueprint $table) {
            $table->dropColumn(['award_cancelled_at', 'award_cancelled_by_user_id', 'award_cancellation_reason']);
        });

        Schema::table('proposals', function (Blueprint $table) {
            $table->dropColumn(['quotation_number']);
        });
    }
};

