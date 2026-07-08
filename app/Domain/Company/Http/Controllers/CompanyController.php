<?php

namespace App\Domain\Company\Http\Controllers;

use App\Domain\Company\Actions\GetMyCompaniesAction;
use App\Domain\Company\Actions\RegisterCompanyAction;
use App\Domain\Company\Actions\UpdateCompanyAction;
use App\Domain\Company\Actions\UploadCompanyDocumentAction;
use App\Domain\Company\Actions\UploadCompanyLogoAction;
use App\Domain\Company\Actions\VerifyNpwpAction;
use App\Domain\Company\Http\Requests\RegisterCompanyRequest;
use App\Domain\Company\Http\Requests\UpdateCompanyRequest;
use App\Domain\Company\Http\Requests\UploadCompanyDocumentRequest;
use App\Domain\Company\Http\Requests\UploadCompanyLogoRequest;
use App\Domain\Company\Http\Requests\VerifyNpwpRequest;
use App\Domain\Company\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Auth;

use App\Domain\Company\Actions\InviteUserAction;
use App\Domain\Company\Actions\AcceptInvitationAction;
use Illuminate\Http\Request;

/**
 * CompanyController
 * 
 * Responsibility: Manage requests related to company profile.
 * Pattern: Thin Controller.
 */
class CompanyController extends \App\Http\Controllers\Controller
{
    /**
     * Display list of companies owned by the currently logged-in user.
     */
    public function myCompanies(Request $request, GetMyCompaniesAction $action): JsonResponse
    {
        $user = $request->user();
        
        // Auto-fix role untuk existing company owners - Enhanced
        if ($user) {
            try {
                $fixed = $user->ensureCompanyOwnerRole();
                if ($fixed) {
                    Log::info('Auto-fixed role in myCompanies endpoint', [
                        'user_id' => $user->id,
                        'user_email' => $user->email
                    ]);
                    
                    // Refresh user untuk memastikan role ter-update
                    $user->refresh();
                }
            } catch (\Exception $e) {
                Log::error('Failed to auto-fix role in myCompanies', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        return response()->json([
            'companies' => $action->execute($user)
        ], 200);
    }

    /**
     * Register a new company profile.
     */
    public function store(RegisterCompanyRequest $request, RegisterCompanyAction $action): JsonResponse
    {
        Log::info('Storing new company', ['payload' => $request->all()]);

        $company = $action->execute($request->user(), $request->validated());
        $data = $company->load('documents')->toArray();
        $data['formatted_tax_id'] = $company->formatted_tax_id;

        return response()->json([
            'message' => 'Company successfully registered.',
            'company' => $data,
        ], 201);
    }

    /**
     * Update company profile information.
     */
    public function update(UpdateCompanyRequest $request, Company $company, UpdateCompanyAction $action): JsonResponse
    {
        $company = $action->execute($company, $request->validated());
        $data = $company->load('documents')->toArray();
        $data['formatted_tax_id'] = $company->formatted_tax_id;

        return response()->json(['company' => $data]);
    }

    /**
     * Verify the company's NPWP number.
     */
    public function verifyNpwp(VerifyNpwpRequest $request, VerifyNpwpAction $action): JsonResponse
    {
        $res = $action->execute(
            $request->input('npwp'),
            $request->input('country', 'ID')
        );

        return response()->json($res, $res['status'] === 1 ? 200 : 422);
    }

    /**
     * Upload company legal documents.
     */
    public function uploadDocument(UploadCompanyDocumentRequest $request, UploadCompanyDocumentAction $action): JsonResponse
    {
        return response()->json($action->execute($request->validated()), 200);
    }

    /**
     * Upload company logo.
     */
    public function uploadLogo(UploadCompanyLogoRequest $request, UploadCompanyLogoAction $action): JsonResponse
    {
        $company = Company::findOrFail($request->input('company_id'));
        $updatedCompany = $action->execute($company, $request->file('logo'));

        /** @var \Illuminate\Filesystem\FilesystemAdapter $storage */
        $storage = Storage::disk(config('filesystems.default'));

        return response()->json([
            'message' => 'Logo successfully updated.',
            'file_path' => $updatedCompany->logo_path,
            'url' => $storage->url($updatedCompany->logo_path),
        ]);
    }

    /**
     * Invite new user to company via WhatsApp.
     */
    public function invite(Request $request, InviteUserAction $action): JsonResponse
    {
        $data = $request->validate([
            'company_id' => 'required|exists:companies,id',
            'whatsapp'   => 'required|string',
            'email'      => 'nullable|email',
            'role'       => 'required|string',
        ]);

        try {
            $result = $action->execute($request->user(), $data);
            return response()->json($result, 201);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }
    }

    /**
     * Accept invitation to join a company.
     */
    public function acceptInvitation(Request $request, AcceptInvitationAction $action): JsonResponse
    {
        $request->validate([
            'token' => 'required|string',
        ]);

        try {
            $result = $action->execute($request->user(), $request->input('token'));
            return response()->json($result, 200);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Invitation is invalid or has expired.'], 400);
        }
    }

    /**
     * Display team members in the company.
     */
    public function teamMembers(Company $company): JsonResponse
    {
        $user = auth()->user();
        
        // Auto-fix role untuk existing company owners - Enhanced
        if ($user) {
            try {
                $fixed = $user->ensureCompanyOwnerRole();
                if ($fixed) {
                    Log::info('Auto-fixed role in teamMembers endpoint', [
                        'user_id' => $user->id,
                        'company_id' => $company->id
                    ]);
                    
                    // Refresh user untuk memastikan role ter-update
                    $user->refresh();
                }
            } catch (\Exception $e) {
                Log::error('Failed to auto-fix role in teamMembers', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage()
                ]);
            }
            
            // Auto-fix company assignment for company owners
            if ($company->owner_id === $user->id && $user->company_id !== $company->id) {
                $user->update(['company_id' => $company->id]);
                $user->refresh();
                
                Log::info('Auto-fixed company_id for company owner in teamMembers', [
                    'user_id' => $user->id,
                    'company_id' => $company->id
                ]);
            }
        }
        
        $members = $company->users()
            ->select('users.id', 'users.name', 'users.email', 'users.whatsapp')
            ->with('roles')
            ->get()
            ->map(function ($user) {
                $data = $user->toArray();
                $data['role'] = $user->role; // Use the accessor
                return $data;
            });

        return response()->json([
            'members' => $members
        ]);
    }

    /**
     * Update user role in the company.
     */
    public function updateUserRole(Request $request, Company $company): JsonResponse
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
            'role' => 'required|string',
        ]);

        $requestingUser = $request->user();
        
        // Auto-fix: If user owns this company but company_id doesn't match, fix it
        if ($company->owner_id === $requestingUser->id && $requestingUser->company_id !== $company->id) {
            $requestingUser->update(['company_id' => $company->id]);
            $requestingUser->refresh();
            
            Log::info('Auto-fixed company_id for company owner', [
                'user_id' => $requestingUser->id,
                'old_company_id' => $requestingUser->company_id,
                'new_company_id' => $company->id
            ]);
        }
        
        // Check if requesting user has permission (must be manager or company owner)
        if ($requestingUser->company_id !== $company->id) {
            return response()->json(['message' => 'You are not authorized to manage this company.'], 403);
        }

        if (!in_array($requestingUser->role, ['manager']) && $company->owner_id !== $requestingUser->id) {
            return response()->json(['message' => 'Only managers and company owners can change user roles.'], 403);
        }

        $targetUser = \App\Domain\Auth\Models\User::findOrFail($request->user_id);
        
        // Check if target user belongs to this company
        if ($targetUser->company_id !== $company->id) {
            return response()->json(['message' => 'User does not belong to this company.'], 403);
        }

        // Prevent changing company owner's role unless they are changing their own
        if ($company->owner_id === $targetUser->id && $requestingUser->id !== $targetUser->id) {
            return response()->json(['message' => 'Company owner role cannot be changed by others.'], 403);
        }

        $newRole = $request->role;

        // Validate role based on company type
        $validRoles = [];
        if ($company->type === 'buyer') {
            $validRoles = ['buyer', 'manager', 'finance'];
        } elseif ($company->type === 'vendor') {
            $validRoles = ['admin', 'manager', 'finance'];
        } else {
            return response()->json(['message' => 'Invalid company type for role assignment.'], 422);
        }

        if (!in_array($newRole, $validRoles)) {
            $validRolesStr = implode(', ', $validRoles);
            return response()->json([
                'message' => "Invalid role for {$company->type} company. Valid roles: {$validRolesStr}"
            ], 422);
        }

        try {
            // Remove existing roles first
            $targetUser->roles()->detach();
            
            // Verify role exists before assignment
            $roleExists = \App\Domain\Access\Models\Role::where('slug', $newRole)->first();
            if (!$roleExists) {
                Log::error('Role not found during assignment', [
                    'requested_role' => $newRole,
                    'company_id' => $company->id,
                    'target_user_id' => $targetUser->id
                ]);
                
                return response()->json([
                    'message' => "Role '{$newRole}' not found in database. Please contact administrator.",
                    'error' => 'role_not_found'
                ], 422);
            }
            
            // Assign new role
            $targetUser->assignRole($newRole);
            
            // Refresh the user model to ensure role is properly loaded
            $targetUser->refresh();
            $targetUser->load('roles'); // Ensure roles relationship is fresh
            
            // Get the actual role from the model (using accessor)
            $actualRole = $targetUser->role;

            Log::info('User role updated successfully', [
                'company_id' => $company->id,
                'target_user_id' => $targetUser->id,
                'requested_role' => $newRole,
                'actual_role' => $actualRole,
                'roles_count' => $targetUser->roles()->count(),
                'updated_by' => $requestingUser->id
            ]);

            return response()->json([
                'message' => 'User role updated successfully.',
                'user' => [
                    'id' => $targetUser->id,
                    'name' => $targetUser->name,
                    'email' => $targetUser->email,
                    'role' => $actualRole // Use actual role from model
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error updating user role', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'company_id' => $company->id,
                'target_user_id' => $targetUser->id,
                'new_role' => $newRole
            ]);

            return response()->json([
                'message' => 'Failed to update user role.',
                'error' => $e->getMessage(),
                'debug_info' => [
                    'role_exists' => \App\Domain\Access\Models\Role::where('slug', $newRole)->exists(),
                    'user_exists' => !is_null($targetUser),
                    'requested_role' => $newRole
                ]
            ], 500);
        }
    }

    /**
     * Diagnose role inconsistencies in the company.
     */
    public function diagnoseRoles(Company $company, \App\Domain\Company\Actions\DiagnoseRoleInconsistenciesAction $action): JsonResponse
    {
        $user = auth()->user();
        if ($user->company_id !== $company->id && $company->owner_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return response()->json($action->execute($user));
    }

    /**
     * Fix role untuk company owner yang belum punya manager role.
     * Endpoint untuk admin atau debugging.
     */
    public function fixCompanyOwnerRole(Company $company): JsonResponse
    {
        $user = auth()->user();
        
        // Hanya owner company atau admin yang bisa akses
        if ($company->owner_id !== $user->id && !$user->hasRole('admin')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if (!$company->owner) {
            return response()->json(['message' => 'Company owner not found'], 404);
        }

        $owner = $company->owner;
        $needsFix = \App\Domain\Auth\Services\RoleFixService::needsRoleFix($owner);
        
        if (!$needsFix) {
            return response()->json([
                'message' => 'Company owner already has correct role',
                'owner' => [
                    'id' => $owner->id,
                    'email' => $owner->email,
                    'current_role' => $owner->role
                ]
            ]);
        }

        $fixed = \App\Domain\Auth\Services\RoleFixService::fixUserRole($owner);
        
        if ($fixed) {
            return response()->json([
                'message' => 'Manager role assigned successfully',
                'owner' => [
                    'id' => $owner->id,
                    'email' => $owner->email,
                    'new_role' => 'manager'
                ]
            ]);
        } else {
            return response()->json([
                'message' => 'Failed to assign manager role',
                'error' => 'See logs for details'
            ], 500);
        }
    }

    /**
     * Get invitation info by token (public endpoint)
     */
    public function invitationInfo(Request $request): JsonResponse
    {
        $request->validate([
            'token' => 'required|string',
        ]);

        $invitation = \App\Domain\Company\Models\CompanyInvitation::where('token', $request->token)
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->with('company:id,name')
            ->first();

        if (!$invitation) {
            return response()->json(['message' => 'Invitation is invalid or has expired.'], 404);
        }

        return response()->json([
            'company' => $invitation->company->name,
            'whatsapp' => $invitation->whatsapp,
            'role' => $invitation->role,
        ]);
    }
}
