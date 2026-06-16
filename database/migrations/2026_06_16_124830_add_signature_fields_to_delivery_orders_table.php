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
        Schema::table('delivery_orders', function (Blueprint $table) {
            $table->foreignUuid('handed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('handed_by_name')->nullable();
            $table->string('handed_by_position')->nullable();
            $table->timestamp('handed_by_signed_at')->nullable();

            $table->foreignUuid('received_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('received_by_name')->nullable();
            $table->string('received_by_position')->nullable();
            $table->timestamp('received_by_signed_at')->nullable();

            $table->foreignUuid('witness_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('witness_name')->nullable();
            $table->string('witness_position')->nullable();
            $table->timestamp('witness_signed_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('delivery_orders', function (Blueprint $table) {
            $table->dropForeign(['handed_by_user_id']);
            $table->dropColumn(['handed_by_user_id', 'handed_by_name', 'handed_by_position', 'handed_by_signed_at']);
            
            $table->dropForeign(['received_by_user_id']);
            $table->dropColumn(['received_by_user_id', 'received_by_name', 'received_by_position', 'received_by_signed_at']);
            
            $table->dropForeign(['witness_user_id']);
            $table->dropColumn(['witness_user_id', 'witness_name', 'witness_position', 'witness_signed_at']);
        });
    }
};
