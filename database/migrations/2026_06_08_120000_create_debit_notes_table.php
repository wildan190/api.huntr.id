<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('debit_notes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('invoice_id')->nullable(); // Related invoice if applicable
            $table->uuid('return_id')->nullable(); // Related return if applicable
            $table->uuid('po_id');
            $table->uuid('buyer_company_id');
            $table->uuid('vendor_company_id');
            
            // Document metadata
            $table->string('debit_note_number')->unique(); // e.g., DN-20260608-001
            $table->date('debit_note_date');
            $table->enum('type', ['return_refund', 'price_adjustment', 'credit_memo', 'charge_back'])->default('return_refund');
            $table->enum('status', ['draft', 'issued', 'acknowledged', 'settled', 'disputed', 'cancelled'])->default('draft');
            
            // Debit note details
            $table->json('line_items')->nullable(); // {description, quantity, unit_price, amount, reason}
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->string('tax_rate')->default('10%'); // e.g., "10%", "15%"
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->string('currency', 3)->default('IDR');
            
            // Reference information
            $table->text('description')->nullable();
            $table->text('reason_for_debit')->nullable();
            $table->string('related_invoice_number')->nullable();
            
            // Supporting documents
            $table->json('attachments')->nullable(); // Array of document URLs/paths
            
            // Approval workflow
            $table->uuid('issued_by_user_id')->nullable();
            $table->timestamp('issued_at')->nullable();
            
            $table->uuid('acknowledged_by_user_id')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->text('acknowledgment_notes')->nullable();
            
            // Payment tracking
            $table->uuid('settled_by_user_id')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->enum('settlement_method', ['credit_memo', 'cash_refund', 'offset_invoice', 'other'])->nullable();
            $table->text('settlement_notes')->nullable();
            
            // Dispute handling
            $table->text('dispute_reason')->nullable();
            $table->uuid('disputed_by_user_id')->nullable();
            $table->timestamp('disputed_at')->nullable();
            $table->text('dispute_resolution')->nullable();
            
            // Document file
            $table->string('document_path')->nullable();
            $table->string('document_url')->nullable();
            
            // Audit
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            
            // Foreign keys
            $table->foreign('invoice_id')->references('id')->on('invoices')->onDelete('set null');
            $table->foreign('return_id')->references('id')->on('returns')->onDelete('set null');
            $table->foreign('po_id')->references('id')->on('purchase_orders')->onDelete('cascade');
            $table->foreign('buyer_company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->foreign('vendor_company_id')->references('id')->on('companies')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('debit_notes');
    }
};
