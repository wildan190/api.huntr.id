<?php

namespace App\Domain\Rfq\Http\Controllers;

use App\Domain\Rfq\Actions\CreateRfqAction;
use App\Domain\Rfq\Actions\ApproveRfqAction;
use App\Domain\Rfq\Actions\GetRfqsAction;
use App\Domain\Rfq\Http\Requests\CreateRfqRequest;
use App\Domain\Rfq\Models\Rfq;
use App\Domain\Company\Models\Company;
use App\Domain\Auth\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * RfqController
 * 
 * Tanggung jawab: Mengelola permintaan terkait Request for Quotation (RFQ).
 * Pola: Thin Controller.
 */
class RfqController extends \App\Http\Controllers\Controller
{
    /**
     * Menampilkan daftar RFQ dengan filter.
     */
    public function index(Request $request, GetRfqsAction $action): JsonResponse
    {
        return response()->json($action->execute($request->all()));
    }

    /**
     * Menampilkan detail RFQ beserta item dan proposalnya.
     */
    public function show(Rfq $rfq): JsonResponse
    {
        return response()->json([
            'rfq' => $rfq->load(['items.catalogue.company', 'company', 'user', 'proposals.company'])
        ], 200);
    }

    /**
     * Membuat RFQ baru (Purchase Requisition).
     */
    public function store(CreateRfqRequest $request, CreateRfqAction $action): JsonResponse
    {
        $company = Company::findOrFail($request->input('company_id'));

        if ($company->type !== 'buyer') {
            return response()->json(['message' => 'Hanya perusahaan Buyer yang dapat membuat RFQ.'], 422);
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

    /**
     * Menyetujui RFQ oleh manajer pembelian.
     */
    public function approve(Request $request, Rfq $rfq, ApproveRfqAction $action): JsonResponse
    {
        $manager = User::findOrFail($request->input('manager_id'));
        
        return response()->json([
            'rfq' => $action->execute($manager, $rfq)
        ], 200);
    }
}
