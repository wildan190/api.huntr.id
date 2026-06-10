<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('basts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('goods_receipt_id')->nullable()->unique();
            $table->uuid('po_id');
            $table->uuid('buyer_company_id');
            $table->uuid('vendor_company_id');
            
            // Document metadata
            $table->string('bast_number')->unique(); // e.g., BAST-20260608-001
            $table->date('bast_date');
            $table->enum('status', ['draft', 'completed', 'cancelled'])->default('draft');
            
            // Handover details
            $table->json('items')->nullable(); // {item_id, quantity_received, condition, notes}
            $table->text('handover_notes')->nullable();
            $table->text('witness_notes')->nullable();
            
            // Signatories
            $table->uuid('handed_by_user_id')->nullable(); // Vendor representative
            $table->string('handed_by_name')->nullable();
            $table->string('handed_by_position')->nullable();
            $table->timestamp('handed_by_signed_at')->nullable();
            
            $table->uuid('received_by_user_id')->nullable(); // Buyer representative
            $table->string('received_by_name')->nullable();
            $table->string('received_by_position')->nullable();
            $table->timestamp('received_by_signed_at')->nullable();
            
            $table->uuid('witness_user_id')->nullable(); // Third party witness
            $table->string('witness_name')->nullable();
            $table->string('witness_position')->nullable();
            $table->timestamp('witness_signed_at')->nullable();
            
            // Document file
            $table->string('document_path')->nullable();
            $table->string('document_url')->nullable();
            
            // Audit
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            
            // Foreign keys
            $table->foreign('goods_receipt_id')->references('id')->on('goods_receipts')->onDelete('cascade');
            $table->foreign('po_id')->references('id')->on('purchase_orders')->onDelete('cascade');
            $table->foreign('buyer_company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->foreign('vendor_company_id')->references('id')->on('companies')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('basts');
    }
};
