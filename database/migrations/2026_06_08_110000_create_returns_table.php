<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('returns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('bast_id')->nullable(); // Related BAST if applicable
            $table->uuid('po_id');
            $table->uuid('goods_receipt_id')->nullable();
            $table->uuid('buyer_company_id');
            $table->uuid('vendor_company_id');
            
            // Document metadata
            $table->string('return_number')->unique(); // e.g., RET-20260608-001
            $table->date('return_date');
            $table->enum('status', ['pending', 'in_transit', 'received', 'processed', 'cancelled'])->default('pending');
            $table->enum('return_reason', ['defective', 'damaged', 'incorrect_qty', 'incorrect_item', 'quality_issue', 'other'])->default('other');
            
            // Return items details
            $table->json('items')->nullable(); // {rfq_item_id, catalogue_id, quantity_returned, unit_price, reason, condition, photos}
            $table->decimal('total_return_value', 15, 2)->default(0);
            $table->text('return_description')->nullable();
            $table->json('photos')->nullable(); // Array of photo URLs
            
            // Return logistics
            $table->string('courier_name')->nullable();
            $table->string('tracking_number')->nullable();
            $table->text('return_address')->nullable();
            $table->timestamp('shipped_date')->nullable();
            $table->timestamp('received_at_vendor')->nullable();
            $table->text('vendor_receiving_notes')->nullable();
            
            // Inspection results
            $table->enum('inspection_status', ['pending', 'approved', 'partial', 'rejected'])->default('pending');
            $table->text('inspection_notes')->nullable();
            $table->uuid('inspected_by_user_id')->nullable();
            $table->timestamp('inspected_at')->nullable();
            
            // Approval workflow
            $table->uuid('approved_by_user_id')->nullable();
            $table->timestamp('approved_at')->nullable();
            
            // Document file
            $table->string('document_path')->nullable();
            $table->string('document_url')->nullable();
            
            // Audit
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            
            // Foreign keys
            $table->foreign('po_id')->references('id')->on('purchase_orders')->onDelete('cascade');
            $table->foreign('goods_receipt_id')->references('id')->on('goods_receipts')->onDelete('set null');
            $table->foreign('bast_id')->references('id')->on('basts')->onDelete('set null');
            $table->foreign('buyer_company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->foreign('vendor_company_id')->references('id')->on('companies')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('returns');
    }
};
