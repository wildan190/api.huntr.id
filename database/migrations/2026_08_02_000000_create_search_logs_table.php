<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_logs', function (Blueprint $table) {
            $table->id();
            $table->string('keyword', 255);
            $table->string('source', 50)->default('regular'); // regular | ai
            $table->string('company_id', 36)->nullable();     // company yang melakukan search
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('searched_at')->useCurrent();

            $table->index('keyword');
            $table->index('searched_at');
            $table->index(['keyword', 'searched_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_logs');
    }
};
