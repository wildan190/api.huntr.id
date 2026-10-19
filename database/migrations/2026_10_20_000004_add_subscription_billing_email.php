<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_checkouts', function (Blueprint $table) {
            $table->string('billing_email')->nullable();
            $table->timestamp('billing_email_sent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('subscription_checkouts', function (Blueprint $table) {
            $table->dropColumn(['billing_email', 'billing_email_sent_at']);
        });
    }
};
