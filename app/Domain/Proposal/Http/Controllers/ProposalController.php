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
use App\Domain\Company\Models\Company;
use App\Domain\Rfq\Models\Rfq;
use Illuminate\Http\Request;

class ProposalController extends \App\Http\Controllers\Controller
{
    public function store(SubmitProposalRequest $request, SubmitProposalAction $action): JsonResponse
    {
        $company = Company::findOrFail($request->input('company_id'));
        $rfq = Rfq::with('items')->findOrFail($request->input('rfq_id'));
        
        $data = $request->validated();
        
        if ($request->hasFile('document')) {
            $data['document_path'] = $request->file('document')->store('proposal_documents', 'public');
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
    public function awardWinner(Request $request, AwardWinnerAction $action): JsonResponse
    {
        $proposal = Proposal::findOrFail($request->input('proposal_id'));
        $rfq = Rfq::findOrFail($request->input('rfq_id'));
        $userId = Auth::id() ?: $request->input('user_id');

        if (!$userId) {
            return response()->json(['message' => 'User ID is required for awarding.'], 401);
        }

        $awardedProposal = $action->execute($proposal, $userId, $rfq);

        return response()->json([
            'message' => 'Proposal awarded as winner. Sending to manager for approval.',
            'proposal' => $awardedProposal->load('rfq', 'company'),
        ]);
    }

    /**
     * Manager approves the awarded winner.
     */
    public function approveWinner(Request $request, ApproveWinnerAction $action): JsonResponse
    {
        $proposal = Proposal::findOrFail($request->input('proposal_id'));
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
    public function awaitingApproval(GetAwaitingApprovalsAction $action): JsonResponse
    {
        $proposals = $action->execute();
        return response()->json(['proposals' => $proposals]);
    }
}
