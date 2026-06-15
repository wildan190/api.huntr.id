<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('efakturs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('bast_id')->unique();
            $table->uuid('po_id');
            $table->uuid('invoice_id')->nullable();
            
            $table->string('nofa')->nullable();
            $table->string('transaction_id')->nullable();
            $table->string('status')->default('CREATED');
            $table->string('no_invoice');
            
            $table->string('masa_pajak')->nullable();
            $table->string('tahun_pajak')->nullable();
            $table->date('tanggal_faktur')->nullable();
            
            $table->decimal('dpp', 15, 2)->default(0);
            $table->decimal('ppn', 15, 2)->default(0);
            
            $table->json('raw_request')->nullable();
            $table->json('raw_response')->nullable();
            
            $table->timestamps();

            $table->foreign('bast_id')->references('id')->on('basts')->onDelete('cascade');
            $table->foreign('po_id')->references('id')->on('purchase_orders')->onDelete('cascade');
            $table->foreign('invoice_id')->references('id')->on('invoices')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('efakturs');
    }
};
