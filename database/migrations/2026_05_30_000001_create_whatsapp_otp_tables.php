<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_otp_codes', function (Blueprint $table) {
            $table->string('whatsapp', 20)->primary();
            $table->string('code', 6);
            $table->timestamp('expires_at');
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('whatsapp_otp_verified', function (Blueprint $table) {
            $table->string('whatsapp', 20)->primary();
            $table->timestamp('expires_at');
            $table->timestamp('verified_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_otp_verified');
        Schema::dropIfExists('whatsapp_otp_codes');
    }
};
