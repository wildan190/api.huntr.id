<?php

namespace App\Domain\Company\Actions;

use App\Domain\Company\Models\CompanyInvitation;
use App\Domain\Auth\Models\User;
use Illuminate\Support\Facades\Log;

class AcceptInvitationAction
{
    /**
     * Accept a company invitation and associate the user with the company and role.
     */
    public function execute(User $user, string $token): array
    {
        $invitation = CompanyInvitation::where('token', $token)
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->with('company')
            ->firstOrFail();

        // Validate role matches company type before accepting
        $this->validateRoleForCompanyType($invitation->role, $invitation->company->type);

        // Update user's company and assign role via Access domain
        $user->update([
            'company_id' => $invitation->company_id,
        ]);
        $user->assignRole($invitation->role);

        // Mark invitation as accepted
        $invitation->update(['status' => 'accepted']);

        return [
            'user' => $user->fresh(),
            'company' => $invitation->company,
        ];
    }

    /**
     * Validate that the role is appropriate for the company type.
     */
    private function validateRoleForCompanyType(string $role, string $companyType): void
    {
        $buyerRoles = ['buyer', 'manager', 'finance'];
        $vendorRoles = ['admin', 'manager', 'finance'];

        if ($companyType === 'buyer' && !in_array($role, $buyerRoles)) {
            throw new \Exception("Role '{$role}' is not valid for buyer companies. Valid roles: " . implode(', ', $buyerRoles));
        }

        if ($companyType === 'vendor' && !in_array($role, $vendorRoles)) {
            throw new \Exception("Role '{$role}' is not valid for vendor companies. Valid roles: " . implode(', ', $vendorRoles));
        }
    }
}
