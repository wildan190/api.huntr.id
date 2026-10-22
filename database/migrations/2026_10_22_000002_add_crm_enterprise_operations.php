<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('crm_contacts', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete(); $table->uuid('customer_id');
            $table->string('name'); $table->string('email')->nullable(); $table->string('phone', 40)->nullable(); $table->string('job_title')->nullable(); $table->boolean('is_primary')->default(false); $table->json('attributes')->nullable(); $table->timestamps();
            $table->index(['company_id', 'customer_id']); $table->index(['company_id', 'email']);
        });
        Schema::create('crm_campaigns', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete(); $table->uuid('created_by')->nullable();
            $table->string('name'); $table->string('channel')->default('email'); $table->string('status')->default('draft'); $table->string('subject')->nullable(); $table->text('body')->nullable(); $table->json('audience')->nullable(); $table->timestamp('scheduled_at')->nullable(); $table->timestamp('sent_at')->nullable(); $table->timestamps();
            $table->index(['company_id', 'status', 'scheduled_at']);
        });
        Schema::create('crm_campaign_recipients', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete(); $table->uuid('campaign_id'); $table->uuid('customer_id')->nullable();
            $table->string('email')->nullable(); $table->string('status')->default('queued'); $table->timestamp('sent_at')->nullable(); $table->timestamp('opened_at')->nullable(); $table->timestamp('clicked_at')->nullable(); $table->timestamps();
            $table->index(['campaign_id', 'status']);
        });
        Schema::create('crm_knowledge_articles', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete(); $table->uuid('author_id')->nullable();
            $table->string('title'); $table->string('slug'); $table->text('body'); $table->string('status')->default('draft'); $table->string('locale', 10)->default('id'); $table->timestamps();
            $table->unique(['company_id', 'slug']); $table->index(['company_id', 'status']);
        });
        Schema::create('crm_integration_connections', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('provider'); $table->string('channel'); $table->string('status')->default('pending'); $table->text('encrypted_credentials')->nullable(); $table->json('settings')->nullable(); $table->timestamp('last_synced_at')->nullable(); $table->timestamps();
            $table->unique(['company_id', 'provider', 'channel']);
        });
        Schema::create('crm_currency_rates', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('base_currency', 3)->default('IDR'); $table->string('quote_currency', 3); $table->decimal('rate', 20, 8); $table->string('source')->default('manual'); $table->timestamp('effective_at'); $table->timestamps();
            $table->unique(['company_id', 'base_currency', 'quote_currency', 'effective_at']);
        });
        Schema::create('crm_quote_approvals', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete(); $table->uuid('quote_id'); $table->uuid('approver_id')->nullable();
            $table->string('department')->nullable(); $table->string('status')->default('pending'); $table->text('note')->nullable(); $table->timestamp('decided_at')->nullable(); $table->timestamps();
            $table->index(['quote_id', 'status']);
        });
        Schema::create('crm_audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete(); $table->uuid('actor_id')->nullable();
            $table->string('entity_type'); $table->uuid('entity_id')->nullable(); $table->string('action'); $table->json('payload')->nullable(); $table->timestamps();
            $table->index(['company_id', 'entity_type', 'created_at']);
        });
    }
    public function down(): void { Schema::dropIfExists('crm_audit_logs'); Schema::dropIfExists('crm_quote_approvals'); Schema::dropIfExists('crm_currency_rates'); Schema::dropIfExists('crm_integration_connections'); Schema::dropIfExists('crm_knowledge_articles'); Schema::dropIfExists('crm_campaign_recipients'); Schema::dropIfExists('crm_campaigns'); Schema::dropIfExists('crm_contacts'); }
};
