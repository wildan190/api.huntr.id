<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goods_receipts', function (Blueprint $table) {
            // Add inspection detail fields
            $table->json('items_inspection')->nullable()->after('received_qty');
            $table->json('accepted_items')->nullable()->after('items_inspection');
            $table->json('rejected_items')->nullable()->after('accepted_items');
            $table->text('inspection_notes')->nullable()->after('rejected_items');
            $table->string('inspection_status')->default('pending')->after('inspection_notes'); // pending, completed
            $table->timestamp('inspected_at')->nullable()->after('inspection_status');
            $table->uuid('inspected_by')->nullable()->after('inspected_at');
            
            $table->foreign('inspected_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->dropForeign(['inspected_by']);
            $table->dropColumn([
                'items_inspection',
                'accepted_items',
                'rejected_items',
                'inspection_notes',
                'inspection_status',
                'inspected_at',
                'inspected_by'
            ]);
        });
    }
};
