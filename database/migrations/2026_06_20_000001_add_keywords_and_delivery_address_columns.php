<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (! Schema::hasColumn('companies', 'keywords')) {
                $table->json('keywords')->nullable()->after('about');
            }
        });

        Schema::table('catalogues', function (Blueprint $table) {
            if (! Schema::hasColumn('catalogues', 'keywords')) {
                $table->json('keywords')->nullable()->after('specifications');
            }
        });

        Schema::table('delivery_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('delivery_orders', 'delivery_address')) {
                $table->text('delivery_address')->nullable()->after('tracking_number');
            }
        });
    }

    public function down(): void
    {
        Schema::table('delivery_orders', function (Blueprint $table) {
            if (Schema::hasColumn('delivery_orders', 'delivery_address')) {
                $table->dropColumn('delivery_address');
            }
        });

        Schema::table('catalogues', function (Blueprint $table) {
            if (Schema::hasColumn('catalogues', 'keywords')) {
                $table->dropColumn('keywords');
            }
        });

        Schema::table('companies', function (Blueprint $table) {
            if (Schema::hasColumn('companies', 'keywords')) {
                $table->dropColumn('keywords');
            }
        });
    }
};
