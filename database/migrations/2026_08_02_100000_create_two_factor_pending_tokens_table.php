<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('two_factor_pending_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('user_id', 36);            // UUID
            $table->string('token', 128)->unique();   // challenge token
            $table->string('device_name', 255)->nullable();
            $table->boolean('remember_me')->default(false);
            $table->timestamp('expires_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['token', 'expires_at']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('two_factor_pending_tokens');
    }
};
