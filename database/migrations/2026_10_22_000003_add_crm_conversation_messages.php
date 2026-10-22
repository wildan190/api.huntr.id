<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('crm_conversation_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->uuid('conversation_id');
            $table->uuid('author_id')->nullable();
            $table->string('direction')->default('inbound');
            $table->text('body');
            $table->json('metadata')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'created_at']);
            $table->index(['company_id', 'direction']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_conversation_messages');
    }
};
