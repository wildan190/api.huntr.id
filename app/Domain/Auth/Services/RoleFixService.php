<?php

namespace App\Domain\Auth\Services;

use App\Domain\Auth\Models\User;
use App\Domain\Company\Models\Company;
use Illuminate\Support\Facades\Log;

/**
 * RoleFixService
 * 
 * Tanggung jawab: Memperbaiki role inconsistencies untuk existing users
 * tanpa mengubah database secara manual.
 */
class RoleFixService
{
    /**
     * Fix role dan company assignment untuk user tertentu jika dia company owner
     */
    public static function fixUserRole(User $user): bool
    {
        $fixed = false;

        // Skip jika user sudah punya manager role dan company assignment sudah benar
        $hasManagerRole = $user->hasRole('manager');
        
        // Cek apakah user adalah owner dari company manapun
        $ownedCompanies = Company::where('owner_id', $user->id)->get();
        
        if ($ownedCompanies->isNotEmpty()) {
            // Fix 1: Assign manager role if missing
            if (!$hasManagerRole) {
                try {
                    $user->assignRole('manager');
                    $fixed = true;
                    
                    Log::info('RoleFixService: Assigned manager role', [
                        'user_id' => $user->id,
                        'user_email' => $user->email,
                        'owned_companies_count' => $ownedCompanies->count(),
                        'fixed_via' => 'RoleFixService'
                    ]);
                } catch (\Exception $e) {
                    Log::error('RoleFixService: Failed to assign manager role', [
                        'user_id' => $user->id,
                        'error' => $e->getMessage()
                    ]);
                    return false;
                }
            }

            // Fix 2: Set company_id to first owned company if not set or wrong
            $primaryCompany = $ownedCompanies->first();
            if ($user->company_id !== $primaryCompany->id) {
                try {
                    $oldCompanyId = $user->company_id;
                    $user->update(['company_id' => $primaryCompany->id]);
                    $fixed = true;
                    
                    Log::info('RoleFixService: Fixed company assignment', [
                        'user_id' => $user->id,
                        'user_email' => $user->email,
                        'old_company_id' => $oldCompanyId,
                        'new_company_id' => $primaryCompany->id,
                        'fixed_via' => 'RoleFixService'
                    ]);
                } catch (\Exception $e) {
                    Log::error('RoleFixService: Failed to fix company assignment', [
                        'user_id' => $user->id,
                        'error' => $e->getMessage()
                    ]);
                    // Don't return false here, role fix might have succeeded
                }
            }
        }
        
        return $fixed;
    }

    /**
     * Fix semua company owners yang belum punya manager role atau company assignment salah
     * Untuk dipakai di batch process atau command
     */
    public static function fixAllCompanyOwners(): array
    {
        $results = [
            'fixed_roles' => 0,
            'fixed_assignments' => 0,
            'already_correct' => 0,
            'errors' => 0,
            'details' => []
        ];

        try {
            $companies = Company::whereNotNull('owner_id')->with('owner')->get();
            
            foreach ($companies as $company) {
                if (!$company->owner) {
                    $results['errors']++;
                    $results['details'][] = [
                        'company_id' => $company->id,
                        'company_name' => $company->name,
                        'status' => 'error',
                        'message' => 'Owner user not found'
                    ];
                    continue;
                }
                
                $owner = $company->owner;
                $roleFixed = false;
                $assignmentFixed = false;
                
                // Check role
                if (!$owner->hasRole('manager')) {
                    if (self::fixUserRole($owner)) {
                        $roleFixed = true;
                        $results['fixed_roles']++;
                    } else {
                        $results['errors']++;
                        $results['details'][] = [
                            'user_id' => $owner->id,
                            'user_email' => $owner->email,
                            'company_name' => $company->name,
                            'status' => 'error',
                            'message' => 'Failed to assign manager role'
                        ];
                        continue;
                    }
                }
                
                // Check company assignment
                if ($owner->company_id !== $company->id) {
                    try {
                        $owner->update(['company_id' => $company->id]);
                        $assignmentFixed = true;
                        $results['fixed_assignments']++;
                    } catch (\Exception $e) {
                        $results['errors']++;
                        $results['details'][] = [
                            'user_id' => $owner->id,
                            'user_email' => $owner->email,
                            'company_name' => $company->name,
                            'status' => 'error',
                            'message' => 'Failed to fix company assignment: ' . $e->getMessage()
                        ];
                        continue;
                    }
                }
                
                if ($roleFixed || $assignmentFixed) {
                    $status = [];
                    if ($roleFixed) $status[] = 'role fixed';
                    if ($assignmentFixed) $status[] = 'company assignment fixed';
                    
                    $results['details'][] = [
                        'user_id' => $owner->id,
                        'user_email' => $owner->email,
                        'company_name' => $company->name,
                        'status' => 'fixed',
                        'message' => implode(' & ', $status)
                    ];
                } else {
                    $results['already_correct']++;
                    $results['details'][] = [
                        'user_id' => $owner->id,
                        'user_email' => $owner->email,
                        'company_name' => $company->name,
                        'status' => 'already_correct'
                    ];
                }
            }

            Log::info('RoleFixService: Batch fix completed', [
                'results' => [
                    'fixed_roles' => $results['fixed_roles'],
                    'fixed_assignments' => $results['fixed_assignments'],
                    'already_correct' => $results['already_correct'],
                    'errors' => $results['errors']
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('RoleFixService: Database error during batch fix', [
                'error' => $e->getMessage()
            ]);
            
            $results['errors']++;
            $results['details'][] = [
                'status' => 'error',
                'message' => 'Database connection error: ' . $e->getMessage()
            ];
        }

        return $results;
    }

    /**
     * Check apakah user perlu role fix tanpa melakukan perubahan
     */
    public static function needsRoleFix(User $user): bool
    {
        if ($user->hasRole('manager')) {
            return false;
        }
        
        return Company::where('owner_id', $user->id)->exists();
    }
}