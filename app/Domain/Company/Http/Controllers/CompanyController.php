<?php

namespace App\Domain\Company\Http\Controllers;

use App\Domain\Company\Actions\RegisterCompanyAction;
use App\Domain\Company\Actions\UpdateCompanyAction;
use App\Domain\Company\Actions\UploadCompanyLogoAction;
use App\Domain\Company\Actions\GetMyCompaniesAction;
use App\Domain\Company\Actions\UploadCompanyDocumentAction;
use App\Domain\Company\Services\PajakIoService;
use App\Domain\Company\Http\Requests\RegisterCompanyRequest;
use App\Domain\Company\Http\Requests\UpdateCompanyRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Domain\Company\Models\Company;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CompanyController extends \App\Http\Controllers\Controller
{
    public function verifyNpwp(Request $request, PajakIoService $service): JsonResponse
    {
        $request->validate([
            'npwp' => ['required', 'string', 'min:15', 'max:16'],
        ]);

        $result = $service->verifyNpwp($request->input('npwp'));

        return response()->json($result);
    }

    public function store(RegisterCompanyRequest $request, RegisterCompanyAction $action): JsonResponse
    {
        Log::info('Storing new company', ['payload' => $request->all()]);

        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'User not found. Please login first.'], 401);
        }

        $company = $action->execute($user, $request->validated());

        Log::info('Company stored successfully', [
            'id' => $company->id,
            'about' => $company->about,
            'industry_type' => $company->industry_type
        ]);

        return response()->json(['company' => $company->load('documents')], 201);
    }

    /**
     * List all companies belonging to a given user.
     * Accepts query param: user_id
     */
    public function myCompanies(Request $request, GetMyCompaniesAction $action): JsonResponse
    {
        $user = $request->user();
        
        if (!$user) {
            return response()->json(['companies' => []], 200);
        }

        $companies = $action->execute($user->id);

        return response()->json(['companies' => $companies], 200);
    }

    public function uploadDocument(Request $request, UploadCompanyDocumentAction $action): JsonResponse
    {
        $validated = $request->validate([
            'company_id' => ['nullable', 'exists:companies,id'],
            'type' => ['nullable', 'string', 'max:100'],
            'document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:10000'],
        ]);

        $result = $action->execute($validated, $request->file('document'));

        return response()->json($result, 200);
    }

    public function uploadLogo(Request $request, UploadCompanyLogoAction $action): JsonResponse
    {
        $validated = $request->validate([
            'company_id' => ['required', 'exists:companies,id'],
            'logo' => ['required', 'image', 'mimes:png,jpg,jpeg,svg', 'max:10240'],
        ]);

        $company = Company::find($validated['company_id']);
        if (!$company) {
            return response()->json(['message' => 'Company not found.'], 404);
        }

        $company = $action->execute($company, $request->file('logo'));

        return response()->json([
            'company' => $company,
            'file_path' => $company->logo_path,
            'url' => Storage::disk(env('FILESYSTEM_DISK', 'public'))->url($company->logo_path),
        ], 200);
    }

    public function update(UpdateCompanyRequest $request, Company $company, UpdateCompanyAction $action): JsonResponse
    {
        try {
            $updatedCompany = $action->execute($company, $request->validated());

            return response()->json([
                'message' => 'Company updated successfully',
                'company' => $updatedCompany->load('documents'),
            ], 200);

        } catch (\Exception $e) {
            Log::error('Update error: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error: ' . $e->getMessage(),
            ], 500);
        }
    }
}

