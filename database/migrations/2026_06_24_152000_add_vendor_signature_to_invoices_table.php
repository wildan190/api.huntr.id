<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add vendor signature fields to invoices table.
     * Auto-filled when vendor confirms PO (no manual action needed).
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('vendor_signed_name')->nullable()->after('total_amount');
            $table->timestamp('vendor_signed_at')->nullable()->after('vendor_signed_name');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['vendor_signed_name', 'vendor_signed_at']);
        });
    }
};
