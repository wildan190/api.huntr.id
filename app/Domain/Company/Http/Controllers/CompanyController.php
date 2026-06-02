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

/**
 * CompanyController
 * 
 * Tanggung jawab: Mengelola permintaan terkait profil perusahaan.
 * Pola: Thin Controller.
 */
class CompanyController extends \App\Http\Controllers\Controller
{
    /**
     * Menampilkan daftar perusahaan milik user yang sedang login.
     */
    public function myCompanies(GetMyCompaniesAction $action): JsonResponse
    {
        return response()->json([
            'companies' => $action->execute(auth()->id())
        ], 200);
    }

    /**
     * Mendaftarkan profil perusahaan baru.
     */
    public function store(RegisterCompanyRequest $request, RegisterCompanyAction $action): JsonResponse
    {
        Log::info('Storing new company', ['payload' => $request->all()]);

        $company = $action->execute($request->user(), $request->validated());

        return response()->json([
            'message' => 'Perusahaan berhasil didaftarkan.',
            'company' => $company->load('documents'),
        ], 201);
    }

    /**
     * Memperbarui informasi profil perusahaan.
     */
    public function update(UpdateCompanyRequest $request, Company $company, UpdateCompanyAction $action): JsonResponse
    {
        $company = $action->execute($company, $request->validated());

        return response()->json(['company' => $company->load('documents')]);
    }

    /**
     * Memverifikasi nomor NPWP perusahaan.
     */
    public function verifyNpwp(VerifyNpwpRequest $request, VerifyNpwpAction $action): JsonResponse
    {
        $res = $action->execute($request->input('npwp'));

        return response()->json($res, $res['status'] === 1 ? 200 : 422);
    }

    /**
     * Mengunggah dokumen legalitas perusahaan.
     */
    public function uploadDocument(UploadCompanyDocumentRequest $request, UploadCompanyDocumentAction $action): JsonResponse
    {
        return response()->json($action->execute($request->validated()), 200);
    }

    /**
     * Mengunggah logo perusahaan.
     */
    public function uploadLogo(UploadCompanyLogoRequest $request, UploadCompanyLogoAction $action): JsonResponse
    {
        $company = Company::findOrFail($request->input('company_id'));
        $updatedCompany = $action->execute($company, $request->file('logo'));

        return response()->json([
            'message' => 'Logo berhasil diperbarui.',
            'file_path' => $updatedCompany->logo_path,
            'url' => Storage::disk(env('FILESYSTEM_DISK', 'public'))->url($updatedCompany->logo_path),
        ]);
    }
}
