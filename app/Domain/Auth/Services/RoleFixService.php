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
     * Fix role untuk user tertentu jika dia company owner tapi belum punya manager role
     */
    public static function fixUserRole(User $user): bool
    {
        // Skip jika user sudah punya manager role
        if ($user->hasRole('manager')) {
            return true;
        }

        // Cek apakah user adalah owner dari company manapun
        $ownedCompaniesCount = Company::where('owner_id', $user->id)->count();
        
        if ($ownedCompaniesCount > 0) {
            try {
                $user->assignRole('manager');
                
                Log::info('RoleFixService: Auto-assigned manager role', [
                    'user_id' => $user->id,
                    'user_email' => $user->email,
                    'owned_companies_count' => $ownedCompaniesCount,
                    'fixed_via' => 'RoleFixService'
                ]);
                
                return true;
                
            } catch (\Exception $e) {
                Log::error('RoleFixService: Failed to assign manager role', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage()
                ]);
                
                return false;
            }
        }
        
        return false;
    }

    /**
     * Fix semua company owners yang belum punya manager role
     * Untuk dipakai di batch process atau command
     */
    public static function fixAllCompanyOwners(): array
    {
        $results = [
            'fixed' => 0,
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
                
                if ($owner->hasRole('manager')) {
                    $results['already_correct']++;
                    $results['details'][] = [
                        'user_id' => $owner->id,
                        'user_email' => $owner->email,
                        'company_name' => $company->name,
                        'status' => 'already_correct'
                    ];
                    continue;
                }
                
                // Fix role
                if (self::fixUserRole($owner)) {
                    $results['fixed']++;
                    $results['details'][] = [
                        'user_id' => $owner->id,
                        'user_email' => $owner->email,
                        'company_name' => $company->name,
                        'status' => 'fixed'
                    ];
                } else {
                    $results['errors']++;
                    $results['details'][] = [
                        'user_id' => $owner->id,
                        'user_email' => $owner->email,
                        'company_name' => $company->name,
                        'status' => 'error',
                        'message' => 'Failed to assign role'
                    ];
                }
            }

            Log::info('RoleFixService: Batch fix completed', [
                'results' => [
                    'fixed' => $results['fixed'],
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