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
        return response()->json([
            'companies' => $action->execute($request->user())
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

        $disk = config('filesystems.default') === 's3' ? 's3' : 'public';
        /** @var \Illuminate\Filesystem\FilesystemAdapter $storage */
        $storage = Storage::disk($disk);

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
