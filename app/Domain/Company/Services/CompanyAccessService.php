<?php

namespace App\Domain\Company\Services;

use App\Domain\Auth\Models\User;
use App\Domain\Company\Models\ApprovalFlow;
use App\Domain\Company\Models\ApprovalRequest;
use App\Domain\Company\Models\ApprovalRequestStep;
use App\Domain\Company\Models\Company;
use App\Domain\Company\Models\CompanyPermission;
use App\Domain\Company\Models\CompanyRole;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Owns company-scoped access policy and sequential approval workflow state.
 * Global legacy roles remain a compatibility fallback until a workspace opens
 * this configuration for the first time.
 */
class CompanyAccessService
{
    public const SUBJECTS = [
        'purchase_request' => 'Purchase request (PR)',
        'winner_approval' => 'Tender winner',
        'po_confirmation' => 'Purchase order confirmation',
    ];

    /** @return array<string, array{name:string,category:string,description:string}> */
    public static function permissionCatalog(): array
    {
        return [
            'team.manage' => ['name' => 'Manage team & access', 'category' => 'Workspace', 'description' => 'Invite members, assign roles, and configure access.'],
            'approval.flows.manage' => ['name' => 'Manage approval flows', 'category' => 'Workspace', 'description' => 'Create and change approval stages and thresholds.'],
            'approval.override' => ['name' => 'Approval override', 'category' => 'Workspace', 'description' => 'Act on any active approval stage in an emergency.'],
            'procurement.pr.view' => ['name' => 'View purchase requests', 'category' => 'Procurement', 'description' => 'View PR and sourcing information.'],
            'procurement.pr.create' => ['name' => 'Create purchase request', 'category' => 'Procurement', 'description' => 'Create a PR for approval or tender.'],
            'procurement.pr.approve' => ['name' => 'Approve purchase request', 'category' => 'Procurement', 'description' => 'Approve PR when assigned to an approval stage.'],
            'procurement.winner.select' => ['name' => 'Select tender winner', 'category' => 'Procurement', 'description' => 'Select a proposal as the tender winner.'],
            'procurement.winner.approve' => ['name' => 'Approve tender winner', 'category' => 'Procurement', 'description' => 'Approve a selected proposal when assigned to an approval stage.'],
            'procurement.po.view' => ['name' => 'View purchase orders', 'category' => 'Procurement', 'description' => 'View issued and received purchase orders.'],
            'procurement.po.confirm' => ['name' => 'Confirm purchase order', 'category' => 'Procurement', 'description' => 'Confirm incoming PO on behalf of a vendor.'],
            'wms.access' => ['name' => 'Access WMS', 'category' => 'Applications', 'description' => 'Access WMS & Inventory after the app is installed.'],
            'wms.view' => ['name' => 'View WMS data', 'category' => 'Warehouse', 'description' => 'View warehouse, stock, receipts, and reports.'],
            'wms.warehouse.manage' => ['name' => 'Manage warehouses', 'category' => 'Warehouse', 'description' => 'Create and manage warehouses, bins, and imports.'],
            'wms.inventory.receive' => ['name' => 'Receive and put away stock', 'category' => 'Warehouse', 'description' => 'Receive goods and move them into storage bins.'],
            'wms.inventory.adjust' => ['name' => 'Adjust inventory', 'category' => 'Warehouse', 'description' => 'Perform stock adjustments and set reorder levels.'],
            'wms.inventory.transfer' => ['name' => 'Transfer inventory', 'category' => 'Warehouse', 'description' => 'Transfer inventory between warehouses and bins.'],
            'wms.orders.fulfill' => ['name' => 'Fulfil warehouse orders', 'category' => 'Warehouse', 'description' => 'Allocate, pick, pack, release, and ship warehouse orders.'],
            'crm.access' => ['name' => 'Access CRM', 'category' => 'Applications', 'description' => 'Access CRM after Enterprise Pro and the app are active.'],
            'crm.view' => ['name' => 'View CRM data', 'category' => 'CRM', 'description' => 'View CRM records assigned to the user.'],
            'crm.manage' => ['name' => 'Manage CRM records', 'category' => 'CRM', 'description' => 'Create and update CRM customers, leads, deals, activities, tickets, and conversations.'],
            'crm.automation.manage' => ['name' => 'Manage CRM automation', 'category' => 'CRM', 'description' => 'Manage campaigns, workflows, targets, and currency rates.'],
            'crm.integration.manage' => ['name' => 'Manage CRM integrations', 'category' => 'CRM', 'description' => 'Connect email, WhatsApp, and other communication channels.'],
            'crm.quote.approve' => ['name' => 'Approve CRM quotes', 'category' => 'CRM', 'description' => 'Approve delegated CRM quote approvals.'],
            'budget.access' => ['name' => 'Access Budgeting & Procurement Plan', 'category' => 'Applications', 'description' => 'Open Budgeting & Procurement Plan after Enterprise Pro and the app are active.'],
            'budget.manage' => ['name' => 'Manage budgets & procurement plans', 'category' => 'Applications', 'description' => 'Create budget periods, allocations, and procurement plans.'],
            'budget.approve' => ['name' => 'Approve procurement plans', 'category' => 'Applications', 'description' => 'Approve or return submitted procurement plans.'],
            'finance.access' => ['name' => 'Access Finance', 'category' => 'Finance', 'description' => 'Access invoices, payment and finance information.'],
        ];
    }

    public function bootstrap(Company $company): void
    {
        DB::transaction(function () use ($company) {
            foreach (self::permissionCatalog() as $key => $definition) {
                CompanyPermission::query()->firstOrCreate(
                    ['company_id' => $company->id, 'key' => $key],
                    ['name' => $definition['name'], 'category' => $definition['category'], 'description' => $definition['description'], 'is_system' => true],
                );
            }

            $defaults = [
                'workspace_admin' => ['Workspace Admin', 'Full workspace access, access management, and emergency approval override.', array_keys(self::permissionCatalog()), true],
                'procurement_officer' => ['Procurement Officer', 'Creates and manages purchase requests and tender selections.', ['procurement.pr.view', 'procurement.pr.create', 'procurement.winner.select', 'procurement.po.view'], false],
                'finance_officer' => ['Finance Officer', 'Accesses finance and purchase order information.', ['finance.access', 'procurement.po.view'], false],
                'warehouse_operator' => ['Warehouse Operator', 'Operates WMS & Inventory.', ['wms.access', 'wms.view', 'wms.inventory.receive', 'wms.inventory.adjust', 'wms.inventory.transfer', 'wms.orders.fulfill'], false],
                'crm_operator' => ['CRM Operator', 'Works with CRM customers, pipeline, and support.', ['crm.access', 'crm.view', 'crm.manage'], false],
                'budget_manager' => ['Budget Manager', 'Plans, allocates, and controls procurement budgets.', ['budget.access', 'budget.manage', 'budget.approve'], false],
                'vendor_operator' => ['Vendor Order Operator', 'Views and confirms incoming purchase orders.', ['procurement.po.view', 'procurement.po.confirm'], false],
            ];

            foreach ($defaults as $slug => [$name, $description, $permissionKeys, $system]) {
                $role = CompanyRole::query()->firstOrCreate(
                    ['company_id' => $company->id, 'slug' => $slug],
                    ['name' => $name, 'description' => $description, 'is_system' => $system],
                );
                // A default is only a starting point. Never overwrite an
                // administrator's later adjustments when the context is read.
                if ($role->wasRecentlyCreated) {
                    $role->permissions()->sync($this->permissionIds($company, $permissionKeys));
                } elseif ($slug === 'workspace_admin') {
                    // Workspace Admin is deliberately a full-access system role.
                    // Add newly released permissions without removing any custom assignment.
                    $role->permissions()->syncWithoutDetaching($this->permissionIds($company, $permissionKeys));
                } elseif ($slug === 'warehouse_operator' || $slug === 'crm_operator') {
                    $role->permissions()->syncWithoutDetaching($this->permissionIds($company, $permissionKeys));
                }
            }

            $members = User::query()->where('company_id', $company->id)->get();
            $owner = User::query()->find($company->owner_id);
            if ($owner && ! $members->contains('id', $owner->id)) {
                $members->push($owner);
            }
            foreach ($members as $member) {
                if (DB::table('company_user_roles')->where('company_id', $company->id)->where('user_id', $member->id)->exists()) {
                    continue;
                }
                $legacy = $member->roles()->pluck('slug')->first();
                $slug = $member->id === $company->owner_id || in_array($legacy, ['manager', 'admin'], true)
                    ? 'workspace_admin'
                    : ($legacy === 'warehouse_admin' ? 'warehouse_operator' : ($legacy === 'finance' ? 'finance_officer' : ($company->type === 'vendor' ? 'vendor_operator' : 'procurement_officer')));
                $roleId = CompanyRole::query()->where('company_id', $company->id)->where('slug', $slug)->value('id');
                DB::table('company_user_roles')->insert(['company_id' => $company->id, 'user_id' => $member->id, 'company_role_id' => $roleId, 'assigned_by' => $company->owner_id, 'created_at' => now(), 'updated_at' => now()]);
            }
        });
    }

    public function configuration(Company $company): array
    {
        $this->bootstrap($company);
        $roles = CompanyRole::query()->where('company_id', $company->id)->with('permissions')->orderBy('is_system', 'desc')->orderBy('name')->get();
        $counts = DB::table('company_user_roles')->where('company_id', $company->id)->selectRaw('company_role_id, count(*) as aggregate')->groupBy('company_role_id')->pluck('aggregate', 'company_role_id');
        $roles->each(fn (CompanyRole $role) => $role->setAttribute('member_count', (int) ($counts[$role->id] ?? 0)));

        return [
            'permission_catalog' => CompanyPermission::query()->where('company_id', $company->id)->orderBy('category')->orderBy('name')->get(),
            'roles' => $roles,
            'flows' => ApprovalFlow::query()->where('company_id', $company->id)->with('steps.role')->orderBy('subject_type')->orderBy('name')->get(),
            'subjects' => $company->type === 'vendor'
                ? ['po_confirmation' => self::SUBJECTS['po_confirmation']]
                : self::SUBJECTS,
        ];
    }

    public function can(User $user, Company $company, string $permission, bool $legacyFallback = true): bool
    {
        if ((string) $company->owner_id === (string) $user->id) {
            return true;
        }
        if ((string) $user->company_id !== (string) $company->id) {
            return false;
        }
        $roleId = DB::table('company_user_roles')->where('company_id', $company->id)->where('user_id', $user->id)->value('company_role_id');
        if ($roleId) {
            return DB::table('company_role_permissions as assignments')
                ->join('company_permissions as permissions', 'permissions.id', '=', 'assignments.company_permission_id')
                ->where('assignments.company_role_id', $roleId)->where('permissions.key', $permission)->exists();
        }

        return $legacyFallback && $user->hasRole('manager');
    }

    public function isMember(User $user, Company $company): bool
    {
        return (string) $company->owner_id === (string) $user->id
            || (string) $user->company_id === (string) $company->id
            || $company->users()->whereKey($user->id)->exists();
    }

    public function assertMember(User $user, Company $company, string $message = 'Anda tidak memiliki akses ke perusahaan ini.'): void
    {
        if (! $this->isMember($user, $company)) {
            throw new AuthorizationException($message);
        }
    }

    public function assertCan(User $user, Company $company, string $permission, string $message = 'Anda tidak memiliki izin untuk tindakan ini.'): void
    {
        if (! $this->can($user, $company, $permission)) {
            throw new AuthorizationException($message);
        }
    }

    public function roleFor(User $user, Company $company): ?CompanyRole
    {
        $roleId = DB::table('company_user_roles')->where('company_id', $company->id)->where('user_id', $user->id)->value('company_role_id');

        return $roleId ? CompanyRole::query()->find($roleId) : null;
    }

    public function saveRole(Company $company, User $actor, array $data, ?CompanyRole $role = null): CompanyRole
    {
        $this->assertCan($actor, $company, 'team.manage');
        $this->bootstrap($company);
        $keys = Arr::wrap($data['permission_keys'] ?? []);
        $permissionIds = $this->permissionIds($company, $keys, true);
        if ($role && $role->company_id !== $company->id) {
            abort(404);
        }
        $role ??= new CompanyRole(['company_id' => $company->id, 'slug' => Str::slug($data['name'])]);
        $role->fill(['name' => $data['name'], 'description' => $data['description'] ?? null]);
        if (! $role->exists) {
            $role->slug = $this->uniqueSlug($company, $data['name']);
        }
        $role->save();
        $role->permissions()->sync($permissionIds);

        return $role->fresh('permissions');
    }

    public function deleteRole(Company $company, User $actor, CompanyRole $role): void
    {
        $this->assertCan($actor, $company, 'team.manage');
        abort_unless((string) $role->company_id === (string) $company->id, 404);
        if ($role->is_system) {
            throw ValidationException::withMessages(['role' => ['System role cannot be deleted. Create and assign a replacement role instead.']]);
        }
        if (DB::table('company_user_roles')->where('company_role_id', $role->id)->exists()) {
            throw ValidationException::withMessages(['role' => ['Move all members out of this role before deleting it.']]);
        }
        if (DB::table('approval_flow_steps')->where('company_role_id', $role->id)->exists()) {
            throw ValidationException::withMessages(['role' => ['This role is used by an approval flow. Update the flow first.']]);
        }
        $role->delete();
    }

    public function createPermission(Company $company, User $actor, array $data): CompanyPermission
    {
        $this->assertCan($actor, $company, 'team.manage');
        $key = 'custom.'.Str::slug($data['name']);
        if (CompanyPermission::query()->where('company_id', $company->id)->where('key', $key)->exists()) {
            throw ValidationException::withMessages(['name' => ['Permission with this name already exists.']]);
        }

        return CompanyPermission::query()->create(['company_id' => $company->id, 'key' => $key, 'name' => $data['name'], 'category' => 'Custom', 'description' => $data['description'] ?? null, 'is_system' => false]);
    }

    public function assignRole(Company $company, User $actor, string $userId, string $roleId): void
    {
        $this->assertCan($actor, $company, 'team.manage');
        $member = User::query()->findOrFail($userId);
        abort_unless((string) $member->company_id === (string) $company->id || (string) $member->id === (string) $company->owner_id, 422, 'User is not a company member.');
        $role = CompanyRole::query()->where('company_id', $company->id)->findOrFail($roleId);
        DB::table('company_user_roles')->updateOrInsert(['company_id' => $company->id, 'user_id' => $member->id], ['company_role_id' => $role->id, 'assigned_by' => $actor->id, 'updated_at' => now(), 'created_at' => now()]);
    }

    public function saveFlow(Company $company, User $actor, array $data, ?ApprovalFlow $flow = null): ApprovalFlow
    {
        $this->assertCan($actor, $company, 'approval.flows.manage');
        $this->bootstrap($company);
        if ($company->type === 'vendor' && $data['subject_type'] !== 'po_confirmation') {
            throw ValidationException::withMessages(['subject_type' => ['Vendor workspace can configure approval for PO confirmation.']]);
        }
        if ($flow && (string) $flow->company_id !== (string) $company->id) {
            abort(404);
        }
        foreach ($data['steps'] as $step) {
            if (! CompanyRole::query()->where('company_id', $company->id)->whereKey($step['company_role_id'])->exists()) {
                throw ValidationException::withMessages(['steps' => ['Each approval stage must use a role in this company.']]);
            }
        }

        return DB::transaction(function () use ($company, $actor, $data, $flow) {
            $flow ??= new ApprovalFlow(['company_id' => $company->id, 'created_by' => $actor->id]);
            $flow->fill(Arr::only($data, ['name', 'subject_type', 'currency', 'min_amount', 'max_amount', 'is_active']));
            $flow->currency = strtoupper($flow->currency ?: 'IDR');
            $flow->save();
            $flow->steps()->delete();
            foreach (array_values($data['steps']) as $index => $step) {
                $flow->steps()->create(['sequence' => $index + 1, 'name' => $step['name'] ?? null, 'company_role_id' => $step['company_role_id'], 'required_approvals' => max(1, (int) ($step['required_approvals'] ?? 1))]);
            }

            return $flow->fresh('steps.role');
        });
    }

    public function deleteFlow(Company $company, User $actor, ApprovalFlow $flow): void
    {
        $this->assertCan($actor, $company, 'approval.flows.manage');
        abort_unless((string) $flow->company_id === (string) $company->id, 404);
        $flow->delete();
    }

    /** @return array{configured:bool,completed:bool,request:?ApprovalRequest} */
    public function approveSubject(Company $company, string $subjectType, string $subjectId, User $actor, float $amount = 0, ?string $requestedBy = null, array $metadata = [], ?string $notes = null): array
    {
        $request = $this->openRequest($company, $subjectType, $subjectId, $amount, $requestedBy, $metadata);
        if (! $request) {
            return ['configured' => false, 'completed' => true, 'request' => null];
        }
        if ($request->status !== 'pending') {
            return ['configured' => true, 'completed' => $request->status === 'approved', 'request' => $request];
        }

        return DB::transaction(function () use ($company, $actor, $request, $notes) {
            $request = ApprovalRequest::query()->lockForUpdate()->findOrFail($request->id);
            abort_unless((string) $request->company_id === (string) $company->id, 404);
            abort_unless($request->status === 'pending', 422, 'Approval request is no longer pending.');
            abort_if((string) $request->requested_by === (string) $actor->id, 403, 'Requester cannot approve their own request.');
            $step = ApprovalRequestStep::query()->where('approval_request_id', $request->id)->where('sequence', $request->current_sequence)->lockForUpdate()->firstOrFail();
            $this->assertApprover($company, $actor, $step);
            abort_if(
                DB::table('approval_request_step_decisions')
                    ->where('approval_request_step_id', $step->id)
                    ->where('approver_id', $actor->id)
                    ->exists(),
                422,
                'Anda sudah memberikan keputusan pada tahap ini.',
            );
            DB::table('approval_request_step_decisions')->insert([
                'id' => (string) Str::uuid(),
                'approval_request_step_id' => $step->id,
                'approver_id' => $actor->id,
                'decision' => 'approved',
                'notes' => $notes,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $approvedCount = DB::table('approval_request_step_decisions')
                ->where('approval_request_step_id', $step->id)
                ->where('decision', 'approved')
                ->count();
            if ($approvedCount < $step->required_approvals) {
                $step->update(['approved_count' => $approvedCount]);

                return ['configured' => true, 'completed' => false, 'request' => $request->fresh('steps.role')];
            }
            $step->update(['status' => 'approved', 'approved_count' => $approvedCount, 'acted_by' => $actor->id, 'acted_at' => now(), 'notes' => $notes]);
            $next = ApprovalRequestStep::query()->where('approval_request_id', $request->id)->where('sequence', '>', $step->sequence)->orderBy('sequence')->first();
            if ($next) {
                $request->update(['current_sequence' => $next->sequence]);

                return ['configured' => true, 'completed' => false, 'request' => $request->fresh('steps.role')];
            }
            $request->update(['status' => 'approved', 'completed_by' => $actor->id, 'completed_at' => now()]);

            return ['configured' => true, 'completed' => true, 'request' => $request->fresh('steps.role')];
        });
    }

    public function rejectSubject(Company $company, ApprovalRequest $request, User $actor, ?string $notes = null): ApprovalRequest
    {
        abort_unless((string) $request->company_id === (string) $company->id, 404);

        return DB::transaction(function () use ($company, $request, $actor, $notes) {
            $request = ApprovalRequest::query()->lockForUpdate()->findOrFail($request->id);
            abort_unless($request->status === 'pending', 422, 'Approval request is no longer pending.');
            $step = ApprovalRequestStep::query()->where('approval_request_id', $request->id)->where('sequence', $request->current_sequence)->lockForUpdate()->firstOrFail();
            $this->assertApprover($company, $actor, $step);
            $step->update(['status' => 'rejected', 'acted_by' => $actor->id, 'acted_at' => now(), 'notes' => $notes]);
            $request->update(['status' => 'rejected', 'completed_by' => $actor->id, 'completed_at' => now()]);

            return $request->fresh('steps.role');
        });
    }

    public function startSubject(Company $company, string $subjectType, string $subjectId, float $amount = 0, ?string $requestedBy = null, array $metadata = []): ?ApprovalRequest
    {
        return $this->openRequest($company, $subjectType, $subjectId, $amount, $requestedBy, $metadata);
    }

    public function queue(Company $company, User $actor): Collection
    {
        $role = $this->roleFor($actor, $company);
        $query = ApprovalRequest::query()->where('company_id', $company->id)->where('status', 'pending')->with('steps.role', 'flow')->latest();
        if (! $this->can($actor, $company, 'approval.override') && $role) {
            $query->whereHas('steps', fn ($steps) => $steps->where('sequence', DB::raw('approval_requests.current_sequence'))->where('company_role_id', $role->id));
        } elseif (! $this->can($actor, $company, 'approval.override')) {
            return collect();
        }

        return $query->get();
    }

    private function openRequest(Company $company, string $subjectType, string $subjectId, float $amount, ?string $requestedBy, array $metadata): ?ApprovalRequest
    {
        $existing = ApprovalRequest::query()->where('company_id', $company->id)->where('subject_type', $subjectType)->where('subject_id', $subjectId)->whereIn('status', ['pending', 'approved'])->latest()->first();
        if ($existing) {
            return $existing;
        }
        $flow = ApprovalFlow::query()->where('company_id', $company->id)->where('subject_type', $subjectType)->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('min_amount')->orWhere('min_amount', '<=', $amount))
            ->where(fn ($query) => $query->whereNull('max_amount')->orWhere('max_amount', '>=', $amount))
            ->orderByDesc('min_amount')->with('steps')->first();
        if (! $flow || $flow->steps->isEmpty()) {
            return null;
        }

        return DB::transaction(function () use ($company, $subjectType, $subjectId, $amount, $requestedBy, $metadata, $flow) {
            $request = ApprovalRequest::query()->create(['company_id' => $company->id, 'approval_flow_id' => $flow->id, 'subject_type' => $subjectType, 'subject_id' => $subjectId, 'amount' => $amount, 'status' => 'pending', 'current_sequence' => 1, 'requested_by' => $requestedBy, 'metadata' => $metadata]);
            foreach ($flow->steps as $step) {
                $request->steps()->create(['approval_flow_step_id' => $step->id, 'company_role_id' => $step->company_role_id, 'sequence' => $step->sequence, 'required_approvals' => $step->required_approvals]);
            }

            return $request->fresh('steps.role');
        });
    }

    private function assertApprover(Company $company, User $actor, ApprovalRequestStep $step): void
    {
        $role = $this->roleFor($actor, $company);
        $matchesStage = $role && (string) $role->id === (string) $step->company_role_id;
        if (! $matchesStage && ! $this->can($actor, $company, 'approval.override')) {
            throw new AuthorizationException('This approval stage is assigned to another role.');
        }
    }

    /** @param array<int, string> $keys */
    private function permissionIds(Company $company, array $keys, bool $validate = false): array
    {
        $ids = CompanyPermission::query()->where('company_id', $company->id)->whereIn('key', $keys)->pluck('id')->all();
        if ($validate && count(array_unique($keys)) !== count($ids)) {
            throw ValidationException::withMessages(['permission_keys' => ['One or more selected permissions do not belong to this company.']]);
        }

        return $ids;
    }

    private function uniqueSlug(Company $company, string $name): string
    {
        $base = Str::limit(Str::slug($name), 110, '');
        $slug = $base ?: 'role';
        $suffix = 2;
        while (CompanyRole::query()->where('company_id', $company->id)->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
