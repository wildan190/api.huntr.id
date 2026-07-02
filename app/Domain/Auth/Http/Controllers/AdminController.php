<?php

namespace App\Domain\Auth\Http\Controllers;

use App\Domain\Auth\Actions\AdminLoginAction;
use App\Domain\Auth\Actions\CreateAdminAction;
use App\Domain\Auth\Models\Admin;
use App\Domain\Auth\Http\Requests\AuditCompanyRequest;
use App\Domain\Company\Actions\AuditCompanyAction;
use App\Domain\Company\Models\Company;
use App\Domain\Company\Repositories\CompanyRepositoryInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminController extends \App\Http\Controllers\Controller
{
    public function login(Request $request, AdminLoginAction $action): JsonResponse
    {
        $credentials = $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        return response()->json($action->execute($credentials));
    }

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

    public function listAdmins(): JsonResponse
    {
        $admins = Admin::all(['id', 'name', 'email', 'created_at']);
        return response()->json(['admins' => $admins]);
    }

    public function createAdmin(Request $request, CreateAdminAction $action): JsonResponse
    {
        $data = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|max:255',
            'password' => 'required|string|min:6',
        ]);

        $admin = $action->execute($data);

        return response()->json([
            'message' => 'Admin successfully created.',
            'admin'   => [
                'id'    => $admin->id,
                'name'  => $admin->name,
                'email' => $admin->email,
            ],
        ], 201);
    }
}
