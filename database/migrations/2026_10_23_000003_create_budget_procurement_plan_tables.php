<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('procurement_budget_cycles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->index();
            $table->string('name');
            $table->unsignedSmallInteger('fiscal_year')->index();
            $table->string('currency', 3)->default('IDR');
            $table->date('starts_at');
            $table->date('ends_at');
            $table->decimal('total_amount', 18, 2)->default(0);
            $table->string('status')->default('draft')->index();
            $table->text('notes')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'name', 'fiscal_year'], 'budget_cycle_company_name_year_unique');
        });

        Schema::create('procurement_budget_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->index();
            $table->uuid('budget_cycle_id')->index();
            $table->string('code', 50);
            $table->string('name');
            $table->string('department', 100)->nullable()->index();
            $table->string('category', 100)->nullable()->index();
            $table->decimal('allocated_amount', 18, 2)->default(0);
            $table->string('currency', 3)->default('IDR');
            $table->uuid('owner_id')->nullable();
            $table->string('status')->default('active')->index();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['budget_cycle_id', 'code'], 'budget_line_cycle_code_unique');
        });

        Schema::create('procurement_plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->index();
            $table->uuid('budget_cycle_id')->index();
            $table->uuid('budget_line_id')->nullable()->index();
            $table->string('plan_number')->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('department', 100)->nullable()->index();
            $table->string('category', 100)->nullable()->index();
            $table->string('priority')->default('normal');
            $table->string('currency', 3)->default('IDR');
            $table->decimal('planned_amount', 18, 2)->default(0);
            $table->date('planned_start_at')->nullable();
            $table->date('planned_end_at')->nullable();
            $table->string('status')->default('draft')->index();
            $table->uuid('rfq_id')->nullable()->index();
            $table->uuid('purchase_order_id')->nullable()->index();
            $table->uuid('created_by')->nullable();
            $table->uuid('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('procurement_plan_approvals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->index();
            $table->uuid('procurement_plan_id')->index();
            $table->uuid('requested_by')->nullable();
            $table->uuid('decided_by')->nullable();
            $table->string('status')->default('pending')->index();
            $table->text('note')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });

        Schema::create('procurement_budget_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->index();
            $table->uuid('budget_cycle_id')->index();
            $table->uuid('budget_line_id')->nullable()->index();
            $table->uuid('procurement_plan_id')->nullable()->index();
            $table->string('kind')->index();
            $table->decimal('amount', 18, 2)->default(0);
            $table->string('currency', 3)->default('IDR');
            $table->string('reference_type', 50)->nullable();
            $table->uuid('reference_id')->nullable();
            $table->string('reference_number')->nullable();
            $table->date('occurred_at')->nullable();
            $table->text('note')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->unique(['procurement_plan_id', 'kind', 'reference_type', 'reference_id'], 'budget_transaction_reference_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('procurement_budget_transactions');
        Schema::dropIfExists('procurement_plan_approvals');
        Schema::dropIfExists('procurement_plans');
        Schema::dropIfExists('procurement_budget_lines');
        Schema::dropIfExists('procurement_budget_cycles');
    }
};
