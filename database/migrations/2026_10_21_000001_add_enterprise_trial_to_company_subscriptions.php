<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_subscriptions', function (Blueprint $table) {
            $table->boolean('is_trial')->default(false)->index();
        });
    }

    public function down(): void
    {
        Schema::table('company_subscriptions', function (Blueprint $table) {
            $table->dropIndex(['is_trial']);
            $table->dropColumn('is_trial');
        });
    }
};
