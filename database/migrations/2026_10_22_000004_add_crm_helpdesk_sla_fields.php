<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('crm_tickets', function (Blueprint $table) {
            $table->timestamp('first_responded_at')->nullable()->after('first_response_due_at');
            $table->timestamp('sla_escalated_at')->nullable()->after('resolve_due_at');
            $table->unsignedTinyInteger('csat_score')->nullable()->after('resolved_at');
            $table->text('csat_comment')->nullable()->after('csat_score');
        });
    }

    public function down(): void
    {
        Schema::table('crm_tickets', function (Blueprint $table) {
            $table->dropColumn(['first_responded_at', 'sla_escalated_at', 'csat_score', 'csat_comment']);
        });
    }
};
