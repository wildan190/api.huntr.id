<?php

namespace App\Domain\Company\Actions;

use App\Domain\Company\Models\CompanyInvitation;
use App\Domain\Auth\Models\User;
use App\Domain\Company\Models\Company;
use App\Domain\Subscription\Services\EnterpriseEntitlementService;
use App\Domain\Company\Services\CompanyAccessService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class AcceptInvitationAction
{
    public function __construct(private EnterpriseEntitlementService $entitlements, private CompanyAccessService $access) {}

    /**
     * Accept a company invitation and associate the user with the company and role.
     */
    public function execute(User $user, string $token): array
    {
        return DB::transaction(function () use ($user, $token) {
            $invitation = CompanyInvitation::where('token', $token)
                ->where('status', 'pending')
                ->where('expires_at', '>', now())
                ->lockForUpdate()
                ->firstOrFail();
            $company = Company::query()->whereKey($invitation->company_id)->lockForUpdate()->firstOrFail();
            $this->validateRoleForCompanyType($invitation->role, $company->type, $invitation->company_id);

            if ((string) $user->company_id !== (string) $company->id && (string) $user->id !== (string) $company->owner_id) {
                $this->entitlements->assertUserCapacity($company);
            }

            $user->update(['company_id' => $invitation->company_id]);
            $user->assignRole($invitation->role);
            if ($invitation->company_role_id) {
                $this->access->bootstrap($company);
                $this->access->assignRole($company, $company->owner, $user->id, $invitation->company_role_id);
            }
            $invitation->update(['status' => 'accepted']);

            return ['user' => $user->fresh(), 'company' => $company];
        });
    }

    /**
     * Validate that the role is appropriate for the company type.
     */
    private function validateRoleForCompanyType(string $role, string $companyType, ?string $companyId = null): void
    {
        $buyerRoles = ['buyer', 'manager', 'finance'];
        if ($companyId && DB::table('company_apps')->where('company_id', $companyId)->where('app_key', 'wms-inventory')->whereNotNull('installed_at')->exists()) {
            $buyerRoles[] = 'warehouse_admin';
        }
        $vendorRoles = ['admin', 'manager', 'finance'];

        if ($companyType === 'buyer' && !in_array($role, $buyerRoles)) {
            throw new \Exception("Role '{$role}' is not valid for buyer companies. Valid roles: " . implode(', ', $buyerRoles));
        }

        if ($companyType === 'vendor' && !in_array($role, $vendorRoles)) {
            throw new \Exception("Role '{$role}' is not valid for vendor companies. Valid roles: " . implode(', ', $vendorRoles));
        }
    }
}
