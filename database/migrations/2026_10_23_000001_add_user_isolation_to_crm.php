<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private const TABLES = [
        'crm_customers', 'crm_territories', 'crm_leads', 'crm_deals', 'crm_activities', 'crm_tickets',
        'crm_ticket_messages', 'crm_email_connections', 'crm_workflows', 'crm_quotes', 'crm_sales_targets',
        'crm_channel_conversations', 'crm_contacts', 'crm_campaigns', 'crm_campaign_recipients',
        'crm_knowledge_articles', 'crm_integration_connections', 'crm_currency_rates',
        'crm_quote_approvals', 'crm_audit_logs', 'crm_conversation_messages', 'crm_whatsapp_session_backups',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->foreignUuid('user_id')->nullable()->after('company_id')->constrained('users')->nullOnDelete();
                $table->index(['company_id', 'user_id']);
            });
            DB::statement("update {$tableName} as record set user_id = companies.owner_id from companies where companies.id = record.company_id and record.user_id is null");
        }

        Schema::table('crm_integration_connections', function (Blueprint $table) {
            $table->dropUnique('crm_integration_connections_company_id_provider_channel_unique');
            $table->unique(['company_id', 'user_id', 'provider', 'channel']);
        });
        Schema::table('crm_email_connections', function (Blueprint $table) {
            $table->dropUnique('crm_email_connections_company_id_email_unique');
            $table->unique(['company_id', 'user_id', 'email']);
        });
        Schema::table('crm_currency_rates', function (Blueprint $table) {
            $table->dropUnique('crm_currency_rates_company_id_base_currency_quote_currency_effective_at_unique');
            $table->unique(['company_id', 'user_id', 'base_currency', 'quote_currency', 'effective_at']);
        });
        Schema::table('crm_knowledge_articles', function (Blueprint $table) {
            $table->dropUnique('crm_knowledge_articles_company_id_slug_unique');
            $table->unique(['company_id', 'user_id', 'slug']);
        });
        Schema::table('crm_whatsapp_session_backups', function (Blueprint $table) {
            $table->dropUnique('crm_whatsapp_session_backups_company_id_unique');
            $table->unique(['company_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::table('crm_integration_connections', fn (Blueprint $table) => $table->dropUnique(['company_id', 'user_id', 'provider', 'channel']));
        Schema::table('crm_email_connections', fn (Blueprint $table) => $table->dropUnique(['company_id', 'user_id', 'email']));
        Schema::table('crm_currency_rates', fn (Blueprint $table) => $table->dropUnique(['company_id', 'user_id', 'base_currency', 'quote_currency', 'effective_at']));
        Schema::table('crm_knowledge_articles', fn (Blueprint $table) => $table->dropUnique(['company_id', 'user_id', 'slug']));
        Schema::table('crm_whatsapp_session_backups', fn (Blueprint $table) => $table->dropUnique(['company_id', 'user_id']));

        foreach (array_reverse(self::TABLES) as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropIndex(['company_id', 'user_id']);
                $table->dropConstrainedForeignId('user_id');
            });
        }
    }
};
