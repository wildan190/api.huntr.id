<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            // Remove the old global unique index on po_number
            // We use try-catch because the index name might vary or already be removed
            try {
                $table->dropUnique(['po_number']);
            } catch (\Exception $e) {
                // Ignore if index doesn't exist
            }

            // Add a composite unique index: po_number + buyer_company_id
            // This ensures po_number is unique WITHIN a company, but can be the same ACROSS companies
            $table->unique(['po_number', 'buyer_company_id'], 'po_number_company_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropUnique('po_number_company_unique');
            $table->unique('po_number');
        });
    }
};
