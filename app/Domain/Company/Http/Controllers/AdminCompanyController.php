<?php

namespace App\Domain\Company\Http\Controllers;

use App\Domain\Company\Actions\AuditCompanyAction;
use App\Domain\Company\Http\Requests\AuditCompanyRequest;
use App\Domain\Company\Models\Company;
use App\Domain\Company\Repositories\CompanyRepositoryInterface;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminCompanyController extends Controller
{
    public function listCompanies(Request $request, CompanyRepositoryInterface $repository): JsonResponse
    {
        $perPage = $request->query('per_page', 10);
        $filters = $request->only(['search', 'status']);

        $companies = $repository->getPaginated($filters, $perPage);
        $stats = $repository->getStats();

        return response()->json(array_merge($companies->toArray(), ['stats' => $stats]));
    }

    public function auditCompany(AuditCompanyRequest $request, AuditCompanyAction $action, Company $company): JsonResponse
    {
        $updatedCompany = $action->execute(
            $company,
            $request->input('action'),
            $request->input('notes')
        );

        return response()->json([
            'message' => 'Company status successfully updated.',
            'company' => $updatedCompany->load('documents'),
        ]);
    }
}
