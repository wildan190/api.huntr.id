<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Domain\Auth\Models\User;
use App\Domain\Company\Models\Company;
use App\Domain\Auth\Services\RoleFixService;
use Illuminate\Support\Facades\Log;

/**
 * DebugRoleController
 * 
 * Tanggung jawab: Debug dan fix role issues di production
 */
class DebugRoleController extends Controller
{
    /**
     * Debug current user role dan company ownership status
     */
    public function debugCurrentUser(Request $request): JsonResponse
    {
        $user = $request->user();
        
        if (!$user) {
            return response()->json(['error' => 'Not authenticated'], 401);
        }

        // Get user roles
        $userRoles = $user->roles()->pluck('slug')->toArray();
        
        // Get owned companies
        $ownedCompanies = Company::where('owner_id', $user->id)->get();
        
        // Get current company
        $currentCompany = $user->company;
        
        // Check if needs role fix
        $needsFix = RoleFixService::needsRoleFix($user);
        
        return response()->json([
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
                'name' => $user->name,
                'company_id' => $user->company_id,
                'current_role_accessor' => $user->role, // Using getRoleAttribute()
                'all_roles' => $userRoles
            ],
            'owned_companies' => $ownedCompanies->map(function($company) {
                return [
                    'id' => $company->id,
                    'name' => $company->name,
                    'type' => $company->type,
                    'owner_id' => $company->owner_id
                ];
            }),
            'current_company' => $currentCompany ? [
                'id' => $currentCompany->id,
                'name' => $currentCompany->name,
                'type' => $currentCompany->type,
                'owner_id' => $currentCompany->owner_id
            ] : null,
            'analysis' => [
                'is_company_owner' => $ownedCompanies->count() > 0,
                'has_manager_role' => in_array('manager', $userRoles),
                'needs_role_fix' => $needsFix,
                'should_be_manager' => $ownedCompanies->count() > 0 && !in_array('manager', $userRoles)
            ]
        ]);
    }

    /**
     * Force fix role untuk current user
     */
    public function forceFixCurrentUser(Request $request): JsonResponse
    {
        $user = $request->user();
        
        if (!$user) {
            return response()->json(['error' => 'Not authenticated'], 401);
        }

        $beforeRoles = $user->roles()->pluck('slug')->toArray();
        $ownedCompanies = Company::where('owner_id', $user->id)->count();
        
        if ($ownedCompanies === 0) {
            return response()->json([
                'message' => 'User is not a company owner, no role fix needed',
                'user_id' => $user->id,
                'owned_companies' => $ownedCompanies
            ]);
        }

        $fixed = RoleFixService::fixUserRole($user);
        
        // Refresh user to get updated roles
        $user->refresh();
        $afterRoles = $user->roles()->pluck('slug')->toArray();
        
        return response()->json([
            'message' => $fixed ? 'Role fixed successfully' : 'Role fix failed',
            'success' => $fixed,
            'user_id' => $user->id,
            'email' => $user->email,
            'owned_companies_count' => $ownedCompanies,
            'roles_before' => $beforeRoles,
            'roles_after' => $afterRoles,
            'role_accessor_after' => $user->role
        ]);
    }

    /**
     * Debug team members untuk company tertentu
     */
    public function debugCompanyTeam(Request $request, $companyId): JsonResponse
    {
        $user = $request->user();
        
        if (!$user) {
            return response()->json(['error' => 'Not authenticated'], 401);
        }

        $company = Company::findOrFail($companyId);
        
        // Check authorization
        if ($user->company_id !== $company->id && $company->owner_id !== $user->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $members = $company->users()
            ->with('roles')
            ->get()
            ->map(function ($member) use ($company) {
                $memberRoles = $member->roles()->pluck('slug')->toArray();
                $isOwner = $company->owner_id === $member->id;
                
                return [
                    'id' => $member->id,
                    'name' => $member->name,
                    'email' => $member->email,
                    'company_id' => $member->company_id,
                    'is_company_owner' => $isOwner,
                    'role_accessor' => $member->role,
                    'all_roles' => $memberRoles,
                    'needs_fix' => $isOwner && !in_array('manager', $memberRoles)
                ];
            });

        return response()->json([
            'company' => [
                'id' => $company->id,
                'name' => $company->name,
                'type' => $company->type,
                'owner_id' => $company->owner_id
            ],
            'members' => $members,
            'summary' => [
                'total_members' => $members->count(),
                'members_needing_fix' => $members->where('needs_fix', true)->count(),
                'company_owner_in_team' => $members->where('is_company_owner', true)->count() > 0
            ]
        ]);
    }

    /**
     * Mass fix untuk semua company owners
     */
    public function massFixCompanyOwners(Request $request): JsonResponse
    {
        $user = $request->user();
        
        // Only admin atau untuk testing
        if (!$user || (!$user->hasRole('admin') && !config('app.debug'))) {
            return response()->json(['error' => 'Unauthorized - Admin only'], 403);
        }

        $results = RoleFixService::fixAllCompanyOwners();
        
        return response()->json([
            'message' => 'Mass fix completed',
            'results' => $results,
            'summary' => [
                'fixed' => $results['fixed'],
                'already_correct' => $results['already_correct'],
                'errors' => $results['errors']
            ]
        ]);
    }

    /**
     * Get info tentang specific user by email (admin only)
     */
    public function debugUserByEmail(Request $request): JsonResponse
    {
        $user = $request->user();
        
        if (!$user || !$user->hasRole('admin')) {
            return response()->json(['error' => 'Admin only'], 403);
        }

        $request->validate([
            'email' => 'required|email'
        ]);

        $targetUser = User::where('email', $request->email)->first();
        
        if (!$targetUser) {
            return response()->json(['error' => 'User not found'], 404);
        }

        $userRoles = $targetUser->roles()->pluck('slug')->toArray();
        $ownedCompanies = Company::where('owner_id', $targetUser->id)->get();
        $needsFix = RoleFixService::needsRoleFix($targetUser);
        
        return response()->json([
            'user' => [
                'id' => $targetUser->id,
                'email' => $targetUser->email,
                'name' => $targetUser->name,
                'company_id' => $targetUser->company_id,
                'role_accessor' => $targetUser->role,
                'all_roles' => $userRoles
            ],
            'owned_companies' => $ownedCompanies,
            'analysis' => [
                'is_company_owner' => $ownedCompanies->count() > 0,
                'has_manager_role' => in_array('manager', $userRoles),
                'needs_role_fix' => $needsFix
            ]
        ]);
    }

    /**
     * Refresh user session - auto-fix roles and return current user data
     * Useful for frontend to call after potential role fixes
     */
    public function refreshSession(Request $request): JsonResponse
    {
        $user = $request->user();
        
        if (!$user) {
            return response()->json(['error' => 'Not authenticated'], 401);
        }

        // Trigger any auto-fixes by accessing role attribute
        $currentRole = $user->role; // This triggers getRoleAttribute() which may auto-fix
        
        // Refresh user model to ensure latest data
        $user->refresh();
        
        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'whatsapp' => $user->whatsapp,
                'role' => $user->role,
                'company_id' => $user->company_id,
                'two_factor_confirmed_at' => $user->two_factor_confirmed_at,
            ],
            'message' => 'Session refreshed successfully',
            'debug' => [
                'roles_count' => $user->roles()->count(),
                'owned_companies' => $user->companies()->count(),
                'needs_fix' => RoleFixService::needsRoleFix($user),
            ]
        ]);
    }
}