<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_permissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->nullable()->index();
            $table->string('key');
            $table->string('name');
            $table->string('category', 80)->default('custom');
            $table->text('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->timestamps();
            $table->unique(['company_id', 'key']);
        });

        Schema::create('company_roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->index();
            $table->string('slug', 120);
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->timestamps();
            $table->unique(['company_id', 'slug']);
        });

        Schema::create('company_role_permissions', function (Blueprint $table) {
            $table->uuid('company_role_id');
            $table->uuid('company_permission_id');
            $table->primary(['company_role_id', 'company_permission_id']);
            $table->foreign('company_role_id')->references('id')->on('company_roles')->cascadeOnDelete();
            $table->foreign('company_permission_id')->references('id')->on('company_permissions')->cascadeOnDelete();
        });

        Schema::create('company_user_roles', function (Blueprint $table) {
            $table->uuid('company_id')->index();
            $table->uuid('user_id')->index();
            $table->uuid('company_role_id');
            $table->uuid('assigned_by')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'user_id']);
            $table->foreign('company_role_id')->references('id')->on('company_roles')->cascadeOnDelete();
        });

        Schema::create('approval_flows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->index();
            $table->string('name', 160);
            $table->string('subject_type', 80)->index();
            $table->string('currency', 3)->default('IDR');
            $table->decimal('min_amount', 20, 2)->nullable();
            $table->decimal('max_amount', 20, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'subject_type', 'is_active']);
        });

        Schema::create('approval_flow_steps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('approval_flow_id');
            $table->unsignedSmallInteger('sequence');
            $table->string('name', 120)->nullable();
            $table->uuid('company_role_id');
            $table->unsignedSmallInteger('required_approvals')->default(1);
            $table->timestamps();
            $table->unique(['approval_flow_id', 'sequence']);
            $table->foreign('approval_flow_id')->references('id')->on('approval_flows')->cascadeOnDelete();
            $table->foreign('company_role_id')->references('id')->on('company_roles')->restrictOnDelete();
        });

        Schema::create('approval_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->index();
            $table->uuid('approval_flow_id')->nullable();
            $table->string('subject_type', 80);
            $table->uuid('subject_id');
            $table->decimal('amount', 20, 2)->default(0);
            $table->string('status', 30)->default('pending');
            $table->unsignedSmallInteger('current_sequence')->default(1);
            $table->uuid('requested_by')->nullable();
            $table->uuid('completed_by')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'subject_type', 'subject_id']);
            $table->index(['company_id', 'status']);
            $table->foreign('approval_flow_id')->references('id')->on('approval_flows')->nullOnDelete();
        });

        Schema::create('approval_request_steps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('approval_request_id');
            $table->uuid('approval_flow_step_id')->nullable();
            $table->uuid('company_role_id');
            $table->unsignedSmallInteger('sequence');
            $table->unsignedSmallInteger('required_approvals')->default(1);
            $table->unsignedSmallInteger('approved_count')->default(0);
            $table->string('status', 30)->default('pending');
            $table->uuid('acted_by')->nullable();
            $table->timestamp('acted_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['approval_request_id', 'sequence']);
            $table->foreign('approval_request_id')->references('id')->on('approval_requests')->cascadeOnDelete();
            $table->foreign('approval_flow_step_id')->references('id')->on('approval_flow_steps')->nullOnDelete();
            $table->foreign('company_role_id')->references('id')->on('company_roles')->restrictOnDelete();
        });

        Schema::table('company_invitations', function (Blueprint $table) {
            $table->uuid('company_role_id')->nullable()->after('role');
            $table->index('company_role_id');
        });
    }

    public function down(): void
    {
        Schema::table('company_invitations', function (Blueprint $table) {
            $table->dropIndex(['company_role_id']);
            $table->dropColumn('company_role_id');
        });
        Schema::dropIfExists('approval_request_steps');
        Schema::dropIfExists('approval_requests');
        Schema::dropIfExists('approval_flow_steps');
        Schema::dropIfExists('approval_flows');
        Schema::dropIfExists('company_user_roles');
        Schema::dropIfExists('company_role_permissions');
        Schema::dropIfExists('company_roles');
        Schema::dropIfExists('company_permissions');
    }
};
