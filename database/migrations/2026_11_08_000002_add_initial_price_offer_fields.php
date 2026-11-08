<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            if (!Schema::hasColumn('proposals', 'initial_price_offer')) {
                $table->decimal('initial_price_offer', 15, 2)->nullable()->after('price_offer');
            }
        });

        Schema::table('proposal_items', function (Blueprint $table) {
            if (!Schema::hasColumn('proposal_items', 'initial_price_offer')) {
                $table->decimal('initial_price_offer', 15, 2)->nullable()->after('price_offer');
            }
        });

        Schema::table('negotiation_items', function (Blueprint $table) {
            if (!Schema::hasColumn('negotiation_items', 'original_price')) {
                $table->decimal('original_price', 15, 2)->nullable()->after('proposal_item_id');
            }
        });

        // Backfill existing records with their current price_offer if initial_price_offer is null
        try {
            DB::table('proposals')->whereNull('initial_price_offer')->update([
                'initial_price_offer' => DB::raw('price_offer')
            ]);
            DB::table('proposal_items')->whereNull('initial_price_offer')->update([
                'initial_price_offer' => DB::raw('price_offer')
            ]);
        } catch (\Throwable $e) {
            // Ignore if in test environment with empty tables
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            if (Schema::hasColumn('proposals', 'initial_price_offer')) {
                $table->dropColumn('initial_price_offer');
            }
        });

        Schema::table('proposal_items', function (Blueprint $table) {
            if (Schema::hasColumn('proposal_items', 'initial_price_offer')) {
                $table->dropColumn('initial_price_offer');
            }
        });

        Schema::table('negotiation_items', function (Blueprint $table) {
            if (Schema::hasColumn('negotiation_items', 'original_price')) {
                $table->dropColumn('original_price');
            }
        });
    }
};

