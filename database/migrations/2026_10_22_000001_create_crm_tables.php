<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_customers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('company_name')->nullable();
            $table->string('job_title')->nullable();
            $table->uuid('external_company_id')->nullable();
            $table->string('lifecycle_stage')->default('prospect');
            $table->string('source')->nullable();
            $table->string('territory')->nullable();
            $table->string('language', 10)->default('id');
            $table->string('currency', 3)->default('IDR');
            $table->json('attributes')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'lifecycle_stage']);
            $table->index(['company_id', 'email']);
            $table->index(['company_id', 'external_company_id']);
        });

        Schema::create('crm_territories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('name');
            $table->string('region')->nullable();
            $table->string('industry')->nullable();
            $table->json('rules')->nullable();
            $table->uuid('owner_id')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'name']);
        });

        Schema::create('crm_leads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->uuid('customer_id')->nullable();
            $table->uuid('owner_id')->nullable();
            $table->uuid('territory_id')->nullable();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('company_name')->nullable();
            $table->string('source')->default('manual');
            $table->string('status')->default('new');
            $table->unsignedSmallInteger('score')->default(0);
            $table->text('score_reason')->nullable();
            $table->decimal('estimated_value', 18, 2)->default(0);
            $table->string('currency', 3)->default('IDR');
            $table->timestamp('next_follow_up_at')->nullable();
            $table->json('attributes')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'score']);
            $table->index(['company_id', 'next_follow_up_at']);
        });

        Schema::create('crm_deals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->uuid('customer_id')->nullable();
            $table->uuid('lead_id')->nullable();
            $table->uuid('owner_id')->nullable();
            $table->uuid('territory_id')->nullable();
            $table->string('name');
            $table->string('stage')->default('qualification');
            $table->unsignedSmallInteger('probability')->default(10);
            $table->decimal('amount', 18, 2)->default(0);
            $table->string('currency', 3)->default('IDR');
            $table->date('expected_close_date')->nullable();
            $table->string('loss_reason')->nullable();
            $table->json('attributes')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'stage']);
            $table->index(['company_id', 'expected_close_date']);
        });

        Schema::create('crm_activities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->uuid('customer_id')->nullable();
            $table->uuid('lead_id')->nullable();
            $table->uuid('deal_id')->nullable();
            $table->uuid('assignee_id')->nullable();
            $table->uuid('created_by')->nullable();
            $table->string('type')->default('task');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status')->default('open');
            $table->timestamp('due_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'status', 'due_at']);
        });

        Schema::create('crm_tickets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->uuid('customer_id')->nullable();
            $table->uuid('assignee_id')->nullable();
            $table->string('number')->unique();
            $table->string('subject');
            $table->text('description')->nullable();
            $table->string('channel')->default('web');
            $table->string('priority')->default('normal');
            $table->string('status')->default('open');
            $table->timestamp('first_response_due_at')->nullable();
            $table->timestamp('resolve_due_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'status', 'priority']);
        });

        Schema::create('crm_ticket_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->uuid('ticket_id');
            $table->uuid('author_id')->nullable();
            $table->boolean('is_internal')->default(false);
            $table->text('body');
            $table->timestamps();
            $table->index(['ticket_id', 'created_at']);
        });

        Schema::create('crm_email_connections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('provider');
            $table->string('email');
            $table->string('status')->default('connected');
            $table->json('settings')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'email']);
        });

        Schema::create('crm_workflows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('name');
            $table->string('trigger');
            $table->json('conditions')->nullable();
            $table->json('actions');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['company_id', 'is_active']);
        });

        Schema::create('crm_quotes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->uuid('customer_id')->nullable();
            $table->uuid('deal_id')->nullable();
            $table->uuid('owner_id')->nullable();
            $table->string('number')->unique();
            $table->string('status')->default('draft');
            $table->string('currency', 3)->default('IDR');
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('discount_amount', 18, 2)->default(0);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->decimal('total_amount', 18, 2)->default(0);
            $table->date('valid_until')->nullable();
            $table->json('lines');
            $table->text('terms')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'status']);
        });

        Schema::create('crm_sales_targets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->uuid('owner_id')->nullable();
            $table->uuid('territory_id')->nullable();
            $table->string('period');
            $table->decimal('target_amount', 18, 2)->default(0);
            $table->string('currency', 3)->default('IDR');
            $table->timestamps();
            $table->index(['company_id', 'period']);
        });

        Schema::create('crm_channel_conversations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->uuid('customer_id')->nullable();
            $table->uuid('assignee_id')->nullable();
            $table->string('channel');
            $table->string('external_id')->nullable();
            $table->string('status')->default('open');
            $table->timestamp('last_message_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'channel', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_channel_conversations');
        Schema::dropIfExists('crm_sales_targets');
        Schema::dropIfExists('crm_quotes');
        Schema::dropIfExists('crm_workflows');
        Schema::dropIfExists('crm_email_connections');
        Schema::dropIfExists('crm_ticket_messages');
        Schema::dropIfExists('crm_tickets');
        Schema::dropIfExists('crm_activities');
        Schema::dropIfExists('crm_deals');
        Schema::dropIfExists('crm_leads');
        Schema::dropIfExists('crm_territories');
        Schema::dropIfExists('crm_customers');
    }
};
