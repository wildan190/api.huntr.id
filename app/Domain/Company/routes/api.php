<?php

use Illuminate\Support\Facades\Route;
use App\Domain\Company\Http\Controllers\CompanyController;
use App\Http\Controllers\DocumentController;

// Public: get invitation info by token (no auth needed — used by register page to pre-fill phone)
Route::get('api/invitations/info', [CompanyController::class, 'invitationInfo'])->middleware(['api', 'cors']);

// Document download routes - require authentication
Route::prefix('api/documents')->middleware(['api', 'auth:api'])->group(function () {
    Route::get('/rfq/{rfqId}', [DocumentController::class, 'downloadRfqDocument']);
    Route::get('/company/{documentId}', [DocumentController::class, 'downloadCompanyDocument']);
    Route::get('/assets/{path}', [DocumentController::class, 'downloadAsset'])->where('path', '.*');
    Route::get('/assets/url', [DocumentController::class, 'getAssetUrl']);
});

Route::prefix('api/companies')->middleware(['api', 'cors', 'auth:api'])->group(function () {
    Route::post('', [CompanyController::class, 'store']);
    Route::post('verify-npwp', [CompanyController::class, 'verifyNpwp']);
    Route::put('{company}', [CompanyController::class, 'update']);
    Route::get('my', [CompanyController::class, 'myCompanies']);
    Route::post('documents/upload', [CompanyController::class, 'uploadDocument']);
    Route::post('logo/upload', [CompanyController::class, 'uploadLogo']);
    Route::post('invite', [CompanyController::class, 'invite']);
    Route::post('accept-invitation', [CompanyController::class, 'acceptInvitation']);
    Route::get('{company}/members', [CompanyController::class, 'teamMembers']);
    Route::put('{company}/users/role', [CompanyController::class, 'updateUserRole']);
    Route::get('{company}/diagnose-roles', [CompanyController::class, 'diagnoseRoles']);
    Route::post('{company}/fix-owner-role', [CompanyController::class, 'fixCompanyOwnerRole']);
    
    // Debug endpoints untuk role troubleshooting
    Route::prefix('debug')->group(function () {
        Route::get('my-role', function(\Illuminate\Http\Request $request) {
            $user = $request->user();
            if (!$user) return response()->json(['error' => 'Not authenticated'], 401);
            
            return response()->json([
                'user_id' => $user->id,
                'email' => $user->email,
                'current_role' => $user->role,
                'all_roles' => $user->roles()->pluck('slug')->toArray(),
                'company_id' => $user->company_id,
                'owned_companies' => \App\Domain\Company\Models\Company::where('owner_id', $user->id)->pluck('name', 'id')->toArray(),
                'needs_fix' => \App\Domain\Auth\Services\RoleFixService::needsRoleFix($user)
            ]);
        });
        
        Route::post('fix-my-role', function(\Illuminate\Http\Request $request) {
            $user = $request->user();
            if (!$user) return response()->json(['error' => 'Not authenticated'], 401);
            
            $before = $user->role;
            $beforeRoles = $user->roles()->pluck('slug')->toArray();
            
            $fixed = \App\Domain\Auth\Services\RoleFixService::fixUserRole($user);
            $user->refresh();
            
            $after = $user->role;
            $afterRoles = $user->roles()->pluck('slug')->toArray();
            
            return response()->json([
                'success' => $fixed,
                'user_id' => $user->id,
                'email' => $user->email,
                'role_before' => $before,
                'role_after' => $after,
                'roles_before' => $beforeRoles,
                'roles_after' => $afterRoles,
                'message' => $fixed ? 'Role fixed successfully' : 'No fix needed or failed'
            ]);
        });
        
        Route::get('refresh-session', function(\Illuminate\Http\Request $request) {
            $user = $request->user();
            if (!$user) return response()->json(['error' => 'Not authenticated'], 401);
            
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
                    'needs_fix' => \App\Domain\Auth\Services\RoleFixService::needsRoleFix($user),
                ]
            ]);
        });
        
        Route::get('{company}/debug-role-update-auth', function(\Illuminate\Http\Request $request, $companyId) {
            $user = $request->user();
            if (!$user) return response()->json(['error' => 'Not authenticated'], 401);
            
            $company = \App\Domain\Company\Models\Company::find($companyId);
            if (!$company) return response()->json(['error' => 'Company not found'], 404);
            
            // Check all authorization conditions
            $userRole = $user->role;
            $userCompanyId = $user->company_id;
            $companyOwnerId = $company->owner_id;
            $isOwner = $companyOwnerId === $user->id;
            $companyMatches = $userCompanyId === $company->id;
            $hasManagerRole = $userRole === 'manager';
            $canUpdateRoles = $hasManagerRole || $isOwner;
            
            return response()->json([
                'user_info' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $userRole,
                    'company_id' => $userCompanyId,
                    'raw_roles' => $user->roles()->pluck('slug')->toArray(),
                ],
                'company_info' => [
                    'id' => $company->id,
                    'name' => $company->name,
                    'owner_id' => $companyOwnerId,
                    'type' => $company->type,
                ],
                'authorization_check' => [
                    'company_matches' => $companyMatches,
                    'is_company_owner' => $isOwner,
                    'has_manager_role' => $hasManagerRole,
                    'can_update_roles' => $canUpdateRoles,
                    'would_be_blocked_by_company_check' => !$companyMatches,
                    'would_be_blocked_by_role_check' => !$canUpdateRoles,
                ],
                'debug_info' => [
                    'auth_condition_1' => "company_id match: {$userCompanyId} === {$company->id} = " . ($companyMatches ? 'true' : 'false'),
                    'auth_condition_2' => "role check: role={$userRole} is manager OR owner_id={$companyOwnerId} === user_id={$user->id} = " . ($canUpdateRoles ? 'true' : 'false'),
                    'overall_authorized' => $companyMatches && $canUpdateRoles ? 'YES' : 'NO',
                ]
            ]);
        });
    });
});

Route::prefix('api/admin')->middleware(['api'])->group(function () {
    Route::get('companies', [\App\Domain\Company\Http\Controllers\AdminCompanyController::class, 'listCompanies']);
    Route::post('companies/{company}/audit', [\App\Domain\Company\Http\Controllers\AdminCompanyController::class, 'auditCompany']);
});

