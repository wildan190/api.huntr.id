<?php

namespace App\Domain\Proposal\Http\Controllers;

use App\Domain\Proposal\Actions\SubmitProposalAction;
use App\Domain\Proposal\Actions\CalculateVendorRankingAction;
use App\Domain\Proposal\Actions\GetVendorMyRankAction;
use App\Domain\Proposal\Actions\AwardWinnerAction;
use App\Domain\Proposal\Actions\ApproveWinnerAction;
use App\Domain\Proposal\Actions\GetAwaitingApprovalsAction;
use App\Domain\Proposal\Http\Requests\SubmitProposalRequest;
use App\Domain\Proposal\Models\Proposal;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\Domain\Company\Models\Company;
use App\Domain\Rfq\Models\Rfq;
use Illuminate\Http\Request;

class ProposalController extends \App\Http\Controllers\Controller
{
    /**
     * Get list of proposals for a company.
     */
    public function index(Request $request): JsonResponse
    {
        $companyId = $request->query('company_id');
        $search = $request->query('search');

        if (!$companyId) {
            return response()->json(['message' => 'Company ID is required.'], 400);
        }

        $company = Company::findOrFail($companyId);

        $query = Proposal::with(['rfq', 'company', 'items.rfqItem.catalogue']);

        if ($company->type === 'buyer') {
            // Buyer sees proposals for their RFQs
            $query->whereHas('rfq', function ($q) use ($companyId) {
                $q->where('company_id', $companyId);
            });
        } else {
            // Vendor sees their own proposals
            $query->where('company_id', $companyId);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->whereHas('rfq', function ($sq) use ($search) {
                    $sq->where('title', 'like', "%{$search}%");
                })->orWhereHas('company', function ($sq) use ($search) {
                    $sq->where('name', 'like', "%{$search}%");
                });
            });
        }

        return response()->json($query->latest()->get());
    }

    public function store(SubmitProposalRequest $request, SubmitProposalAction $action): JsonResponse
    {
        $company = Company::findOrFail($request->input('company_id'));
        $rfq = Rfq::with('items')->findOrFail($request->input('rfq_id'));
        
        $data = $request->validated();
        
        if ($request->hasFile('document')) {
            $data['document_path'] = $request->file('document')->storePublicly('proposal_documents', config('filesystems.default'));
        }

        $proposal = $action->execute($company, $rfq, $data);
        return response()->json(['proposal' => $proposal->load('items.rfqItem.catalogue')], 201);
    }

    /**
     * Calculate rankings for a specific RFQ.
     */
    public function calculateRankings(Rfq $rfq, CalculateVendorRankingAction $action): JsonResponse
    {
        $rankings = $action->execute($rfq);
        return response()->json(['rankings' => $rankings]);
    }

    /**
     * Get overview rankings for a vendor (My Rank page).
     */
    public function vendorRankings(Request $request, GetVendorMyRankAction $action): JsonResponse
    {
        $companyId = $request->query('company_id');
        if (!$companyId) {
            return response()->json(['error' => 'Company ID is required'], 400);
        }

        $company = Company::findOrFail($companyId);
        $data = $action->execute($company);

        return response()->json($data);
    }

    /**
     * Buyer awards a proposal as winner.
     */
    public function awardWinner(Request $request, Proposal $proposal, AwardWinnerAction $action): JsonResponse
    {
        try {
            Log::info('Award winner request received', [
                'proposal_id' => $proposal->id,
                'request_input' => $request->all(),
                'auth_id' => Auth::id()
            ]);

            $rfqId = $request->input('rfq_id');
            if (!$rfqId) {
                Log::warning('Missing rfq_id in request');
                return response()->json(['message' => 'rfq_id is required'], 400);
            }

            $rfq = Rfq::findOrFail($rfqId);
            $userId = Auth::id() ?: $request->input('user_id');

            Log::info('Award winner params', [
                'rfq_id' => $rfqId,
                'user_id' => $userId
            ]);

            if (!$userId) {
                return response()->json(['message' => 'User ID is required for awarding.'], 401);
            }

            $awardedProposal = $action->execute($proposal, $userId, $rfq);

            return response()->json([
                'message' => 'Proposal awarded as winner. Sending to manager for approval.',
                'proposal' => $awardedProposal->load('rfq', 'company'),
            ]);
        } catch (\Exception $e) {
            Log::error('Error in awardWinner', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_input' => $request->all()
            ]);
            return response()->json([
                'message' => 'Error awarding proposal: ' . $e->getMessage()
            ], 400);
        }
    }

    /**
     * Manager approves the awarded winner.
     */
    public function approveWinner(Request $request, Proposal $proposal, ApproveWinnerAction $action): JsonResponse
    {
        $userId = Auth::id() ?: $request->input('user_id');

        if (!$userId) {
            return response()->json(['message' => 'User ID is required for approval.'], 401);
        }

        $approvedProposal = $action->execute($proposal, $userId);

        return response()->json([
            'message' => 'Winner approved successfully.',
            'proposal' => $approvedProposal->load('rfq', 'company'),
        ]);
    }

    /**
     * Get proposal detail with full offer information.
     */
    public function show(Proposal $proposal): JsonResponse
    {
        return response()->json(
            $proposal->load('rfq.items.catalogue', 'company', 'items.rfqItem.catalogue')
        );
    }

    /**
     * Get all proposals awaiting manager approval.
     */
    public function awaitingApproval(Request $request, GetAwaitingApprovalsAction $action): JsonResponse
    {
        $companyId = $request->query('company_id');
        $proposals = $action->execute($companyId);
        return response()->json(['proposals' => $proposals]);
    }
}
