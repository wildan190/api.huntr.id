<?php

namespace App\Domain\Rfq\Http\Controllers;

use App\Domain\Rfq\Actions\CreateRfqAction;
use App\Domain\Rfq\Http\Requests\CreateRfqRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Domain\Company\Models\Company;
use App\Domain\Rfq\Models\Rfq;
use App\Domain\Rfq\Actions\ApproveRfqAction;
use App\Domain\Auth\Models\User;

class RfqController extends \App\Http\Controllers\Controller
{
    public function index(Request $request): JsonResponse
    {
        $companyId = $request->query('company_id');
        $userId = $request->query('user_id');
        $status = $request->query('status');
        
        $query = Rfq::with(['items.catalogue', 'company']);
        
        if ($companyId) {
            $query->where('company_id', $companyId);
        }

        if ($userId) {
            $query->where('user_id', $userId);
        }
        
        if ($status) {
            $query->where('status', $status);
        }
        
        return response()->json($query->latest()->get());
    }

    public function store(CreateRfqRequest $request, CreateRfqAction $action): JsonResponse
    {
        $company = Company::findOrFail($request->input('company_id'));

        if ($company->type !== 'buyer') {
            return response()->json(['message' => 'Hanya perusahaan Buyer yang dapat membuat Purchase Requisition.'], 422);
        }

        $data = $request->validated();
        
        $rfq = $action->execute(
            $company, 
            $data['title'], 
            $data['description'] ?? '', 
            $data['items'],
            $data['user_id'] ?? null,
            $data['status'] ?? 'pending_approval'
        );
        
        return response()->json(['rfq' => $rfq], 201);
    }

    public function approve(Request $request, Rfq $rfq, ApproveRfqAction $action): JsonResponse
    {
        $managerId = $request->input('manager_id');
        $manager = User::findOrFail($managerId);
        
        $rfq = $action->execute($manager, $rfq);
        return response()->json(['rfq' => $rfq], 200);
    }
}
