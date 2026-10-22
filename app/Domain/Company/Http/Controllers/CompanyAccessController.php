<?php

namespace App\Domain\Company\Http\Controllers;

use App\Domain\Company\Actions\ApproveWorkflowRequestAction;
use App\Domain\Company\Http\Requests\StoreApprovalFlowRequest;
use App\Domain\Company\Http\Requests\StoreCompanyRoleRequest;
use App\Domain\Company\Models\ApprovalFlow;
use App\Domain\Company\Models\ApprovalRequest;
use App\Domain\Company\Models\Company;
use App\Domain\Company\Models\CompanyRole;
use App\Domain\Company\Services\CompanyAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Thin HTTP adapter for company roles, permissions, and approval flows. */
class CompanyAccessController
{
    public function show(Request $request, Company $company, CompanyAccessService $service): JsonResponse
    {
        $service->bootstrap($company);
        $service->assertCan($request->user(), $company, 'team.manage', 'Role Anda belum memiliki izin untuk melihat konfigurasi akses.');

        return response()->json($service->configuration($company));
    }

    public function storeRole(StoreCompanyRoleRequest $request, Company $company, CompanyAccessService $service): JsonResponse
    {
        return response()->json(['role' => $service->saveRole($company, $request->user(), $request->validated())], 201);
    }

    public function updateRole(StoreCompanyRoleRequest $request, Company $company, CompanyRole $role, CompanyAccessService $service): JsonResponse
    {
        return response()->json(['role' => $service->saveRole($company, $request->user(), $request->validated(), $role)]);
    }

    public function destroyRole(Request $request, Company $company, CompanyRole $role, CompanyAccessService $service): JsonResponse
    {
        $service->deleteRole($company, $request->user(), $role);

        return response()->json(['message' => 'Role deleted.']);
    }

    public function storePermission(Request $request, Company $company, CompanyAccessService $service): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        return response()->json(['permission' => $service->createPermission($company, $request->user(), $data)], 201);
    }

    public function assignRole(Request $request, Company $company, CompanyAccessService $service): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'uuid'],
            'company_role_id' => ['required', 'uuid'],
        ]);
        $service->assignRole($company, $request->user(), $data['user_id'], $data['company_role_id']);

        return response()->json(['message' => 'Member role updated.']);
    }

    public function storeFlow(StoreApprovalFlowRequest $request, Company $company, CompanyAccessService $service): JsonResponse
    {
        return response()->json(['flow' => $service->saveFlow($company, $request->user(), $request->validated())], 201);
    }

    public function updateFlow(StoreApprovalFlowRequest $request, Company $company, ApprovalFlow $flow, CompanyAccessService $service): JsonResponse
    {
        return response()->json(['flow' => $service->saveFlow($company, $request->user(), $request->validated(), $flow)]);
    }

    public function destroyFlow(Request $request, Company $company, ApprovalFlow $flow, CompanyAccessService $service): JsonResponse
    {
        $service->deleteFlow($company, $request->user(), $flow);

        return response()->json(['message' => 'Approval flow deleted.']);
    }

    public function queue(Request $request, Company $company, CompanyAccessService $service): JsonResponse
    {
        return response()->json(['requests' => $service->queue($company, $request->user())]);
    }

    public function approve(Request $request, Company $company, ApprovalRequest $approvalRequest, ApproveWorkflowRequestAction $action): JsonResponse
    {
        $result = $action->execute($company, $approvalRequest, $request->user(), $request->input('notes'));

        return response()->json([
            'approval' => $result['request'],
            'completed' => $result['completed'],
            'entity' => $result['entity'] ?? null,
        ]);
    }

    public function reject(Request $request, Company $company, ApprovalRequest $approvalRequest, CompanyAccessService $service): JsonResponse
    {
        return response()->json([
            'approval' => $service->rejectSubject($company, $approvalRequest, $request->user(), $request->input('notes')),
        ]);
    }
}
