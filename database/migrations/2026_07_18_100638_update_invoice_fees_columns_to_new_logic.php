<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // Drop old ppn_ecomm column if it exists
            if (Schema::hasColumn('invoices', 'ppn_ecomm')) {
                $table->dropColumn('ppn_ecomm');
            }

            // Add new columns
            if (!Schema::hasColumn('invoices', 'ppn_platform')) {
                $table->decimal('ppn_platform', 15, 2)->default(0)->after('platform_fee');
            }
            if (!Schema::hasColumn('invoices', 'pph23')) {
                $table->decimal('pph23', 15, 2)->default(0)->after('ppn_platform');
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (Schema::hasColumn('invoices', 'ppn_platform')) {
                $table->dropColumn('ppn_platform');
            }
            if (Schema::hasColumn('invoices', 'pph23')) {
                $table->dropColumn('pph23');
            }
            if (!Schema::hasColumn('invoices', 'ppn_ecomm')) {
                $table->decimal('ppn_ecomm', 15, 2)->default(0)->after('midtrans_fee');
            }
        });
    }
};
