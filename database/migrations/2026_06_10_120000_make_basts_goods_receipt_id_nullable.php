<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('basts', function (Blueprint $table) {
            // Make goods_receipt_id nullable and remove unique constraint
            $table->uuid('goods_receipt_id')->nullable()->change();
            // Drop the unique constraint on goods_receipt_id
            $table->dropUnique(['goods_receipt_id']);
        });
    }

    public function down(): void
    {
        Schema::table('basts', function (Blueprint $table) {
            // Restore to not nullable with unique constraint
            $table->uuid('goods_receipt_id')->change();
            $table->unique('goods_receipt_id');
        });
    }
};
