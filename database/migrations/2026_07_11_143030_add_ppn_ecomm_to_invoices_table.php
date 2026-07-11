<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // PPN eComm: 8% dari (platform_fee + midtrans_fee)
            $table->decimal('ppn_ecomm', 15, 2)->default(0)->after('midtrans_fee');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('ppn_ecomm');
        });
    }
};
