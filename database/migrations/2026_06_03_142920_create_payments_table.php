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
        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('invoice_id');
            $table->string('external_id')->unique(); // Midtrans order_id
            $table->string('transaction_id')->nullable(); // Midtrans transaction_id
            $table->decimal('amount', 15, 2);
            $table->string('payment_type')->nullable(); // qris, bank_transfer, gopay, etc
            $table->string('payment_method')->nullable(); // bca, mandiri, etc
            $table->string('status')->default('pending'); // pending, settlement, failure, expire
            $table->json('payment_info')->nullable(); // VA number, QR string, etc
            $table->json('raw_response')->nullable();
            $table->timestamps();

            $table->foreign('invoice_id')->references('id')->on('invoices')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
