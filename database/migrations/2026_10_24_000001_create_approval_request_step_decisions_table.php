<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_request_step_decisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('approval_request_step_id');
            $table->uuid('approver_id');
            $table->string('decision', 20)->default('approved');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['approval_request_step_id', 'approver_id']);
            $table->foreign('approval_request_step_id')
                ->references('id')->on('approval_request_steps')->cascadeOnDelete();
            $table->index('approver_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_request_step_decisions');
    }
};
