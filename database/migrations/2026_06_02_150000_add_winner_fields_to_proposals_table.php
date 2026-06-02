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
        Schema::table('proposals', function (Blueprint $table) {
            $table->string('winner_status')->default('pending')->after('status'); // pending, awarded, approved, rejected
            $table->timestamp('awarded_at')->nullable()->after('winner_status'); // When buyer awards this as winner
            $table->unsignedBigInteger('awarded_by_user_id')->nullable()->after('awarded_at'); // Buyer user who made the award decision
            $table->timestamp('approved_at')->nullable()->after('awarded_by_user_id'); // When manager approves the winner
            $table->unsignedBigInteger('approved_by_user_id')->nullable()->after('approved_at'); // Manager user who approved
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            $table->dropColumn(['winner_status', 'awarded_at', 'awarded_by_user_id', 'approved_at', 'approved_by_user_id']);
        });
    }
};
