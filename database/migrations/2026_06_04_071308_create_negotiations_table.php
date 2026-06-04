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
        Schema::create('negotiations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('proposal_id');
            $table->uuid('buyer_id');
            $table->string('status')->default('pending'); // pending, accepted, declined
            $table->string('payment_scheme')->nullable();
            $table->string('delivery_terms')->nullable();
            $table->text('buyer_remarks')->nullable();
            $table->text('vendor_remarks')->nullable();
            $table->timestamps();
        });

        Schema::create('negotiation_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('negotiation_id');
            $table->uuid('proposal_item_id')->nullable();
            $table->decimal('negotiated_price', 15, 2);
            $table->integer('negotiated_qty');
            $table->timestamps();
        });

        // Update purchase_orders status column if needed, or just document it.
        // Since we are adding new statuses, we should ensure the application logic handles them.
        // The user wants: issued -> confirmed -> paid -> delivery -> delivered -> done
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('negotiation_items');
        Schema::dropIfExists('negotiations');
    }
};
