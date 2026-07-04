<?php

namespace App\Domain\Company\Actions;

use App\Domain\Auth\Models\User;
use App\Domain\Company\Models\Company;

class DiagnoseRoleInconsistenciesAction
{
    /**
     * Diagnose role inconsistencies for the current user's company.
     */
    public function execute(User $user): array
    {
        $company = $user->company;
        if (!$company) {
            return ['error' => 'User is not associated with any company'];
        }

        $buyerRoles = ['buyer', 'manager', 'finance'];
        $vendorRoles = ['admin', 'manager', 'finance'];
        
        $validRoles = $company->type === 'buyer' ? $buyerRoles : $vendorRoles;
        
        // Get all team members with roles
        $teamMembers = $company->users()
            ->with('roles')
            ->get()
            ->map(function ($teamUser) use ($validRoles, $company) {
                $userRole = $teamUser->role;
                $isValid = in_array($userRole, $validRoles);
                
                return [
                    'id' => $teamUser->id,
                    'name' => $teamUser->name,
                    'email' => $teamUser->email,
                    'role' => $userRole,
                    'is_valid_for_company_type' => $isValid,
                    'suggested_role' => !$isValid ? $this->suggestRole($userRole, $company->type) : null,
                ];
            });

        $inconsistencies = $teamMembers->filter(fn($member) => !$member['is_valid_for_company_type']);
        
        return [
            'company' => [
                'id' => $company->id,
                'name' => $company->name,
                'type' => $company->type,
            ],
            'valid_roles_for_company_type' => $validRoles,
            'team_members' => $teamMembers->toArray(),
            'inconsistencies_found' => $inconsistencies->count(),
            'inconsistent_members' => $inconsistencies->toArray(),
        ];
    }

    private function suggestRole(string $currentRole, string $companyType): string
    {
        if ($companyType === 'buyer') {
            return match($currentRole) {
                'admin' => 'buyer',
                default => 'buyer'
            };
        } else { // vendor
            return match($currentRole) {
                'buyer' => 'admin',
                default => 'admin'
            };
        }
    }
}