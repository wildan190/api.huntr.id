<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('efakturs', function (Blueprint $table) {
            // ID internal PajakExpress (integer dari response create)
            $table->string('pajak_express_id')->nullable()->after('invoice_id');

            // Jenis faktur: VAT_OUT (keluaran) | VAT_IN (masukan)
            $table->string('vat_type')->default('VAT_OUT')->after('pajak_express_id');

            // Untuk VAT In: NPWP penjual
            $table->string('npwp_penjual')->nullable()->after('vat_type');

            // kd jenis transaksi (untuk cancel: TD.00301, TD.00304, dll)
            $table->string('kd_jenis_transaksi')->default('TD.00304')->after('npwp_penjual');
        });
    }

    public function down(): void
    {
        Schema::table('efakturs', function (Blueprint $table) {
            $table->dropColumn(['pajak_express_id', 'vat_type', 'npwp_penjual', 'kd_jenis_transaksi']);
        });
    }
};
