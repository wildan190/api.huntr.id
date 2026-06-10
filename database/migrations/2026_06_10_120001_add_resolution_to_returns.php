<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('returns', function (Blueprint $table) {
            // Resolution workflow fields
            $table->string('resolution_type')->nullable()->after('inspection_notes'); // replacement, refund, partial_refund, credit_note
            $table->string('resolution_status')->default('pending_vendor')->after('resolution_type'); // pending_vendor, proposed, buyer_approved, rejected, completed
            $table->json('resolution_details')->nullable()->after('resolution_status'); // Details of proposed resolution
            $table->text('vendor_proposal_notes')->nullable()->after('resolution_details');
            $table->text('buyer_response_notes')->nullable()->after('vendor_proposal_notes');
            $table->timestamp('resolution_proposed_at')->nullable()->after('buyer_response_notes');
            $table->timestamp('resolution_approved_at')->nullable()->after('resolution_proposed_at');
            $table->uuid('resolution_proposed_by')->nullable()->after('resolution_approved_at');
            $table->uuid('resolution_approved_by')->nullable()->after('resolution_proposed_by');
            
            $table->foreign('resolution_proposed_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('resolution_approved_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('returns', function (Blueprint $table) {
            $table->dropForeign(['resolution_proposed_by']);
            $table->dropForeign(['resolution_approved_by']);
            $table->dropColumn([
                'resolution_type',
                'resolution_status',
                'resolution_details',
                'vendor_proposal_notes',
                'buyer_response_notes',
                'resolution_proposed_at',
                'resolution_approved_at',
                'resolution_proposed_by',
                'resolution_approved_by'
            ]);
        });
    }
};
