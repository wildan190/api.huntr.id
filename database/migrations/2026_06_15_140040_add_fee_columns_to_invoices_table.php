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
        Schema::table('invoices', function (Blueprint $table) {
            $table->decimal('base_amount', 15, 2)->default(0)->after('type'); // original PO amount
            $table->decimal('platform_fee', 15, 2)->default(0)->after('base_amount');
            $table->decimal('midtrans_fee', 15, 2)->default(0)->after('platform_fee');
            $table->decimal('ppn_fee', 15, 2)->default(0)->after('midtrans_fee');
            $table->decimal('total_amount', 15, 2)->default(0)->after('ppn_fee');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn([
                'base_amount',
                'platform_fee',
                'midtrans_fee',
                'ppn_fee',
                'total_amount'
            ]);
        });
    }
};
