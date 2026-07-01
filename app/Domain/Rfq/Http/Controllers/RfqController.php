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
            'rfq' => $rfq->load([
                'items.catalogue.company', 
                'company', 
                'user', 
                'proposals' => function($query) {
                    $query->with('company');
                }
            ])
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
        
        $documentPath = null;
        if ($request->hasFile('document')) {
            $disk = config('filesystems.default') === 's3' ? 's3' : 'public';
            $documentPath = $request->file('document')->store('rfq_documents', $disk);
        }

        $rfq = $action->execute(
            $company,
            $data['title'],
            $data['description'] ?? '',
            $data['items'],
            $data['user_id'] ?? null,
            $data['status'] ?? 'pending_approval',
            $data['duration_days'] ?? 7,
            $documentPath,
            $data['delivery_point'] ?? null
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

    /**
     * Mendapatkan perankingan proposal untuk RFQ tertentu.
     */
    public function rankings(Rfq $rfq): JsonResponse
    {
        $rankings = $rfq->proposals()
            ->with(['company', 'items.rfqItem.catalogue'])
            ->orderBy('price_offer', 'asc')
            ->get()
            ->map(function ($proposal, $index) {
                return [
                    'rank' => $index + 1,
                    'proposal' => $proposal,
                    'is_winner' => $proposal->winner_status === 'awarded' || $proposal->winner_status === 'approved'
                ];
            });

        return response()->json(['rankings' => $rankings], 200);
    }
}
