<?php

namespace App\Domain\Negotiation\Http\Controllers;

use App\Domain\Negotiation\Models\Negotiation;
use App\Domain\Negotiation\Actions\CreateNegotiationAction;
use App\Domain\Negotiation\Actions\RespondToNegotiationAction;
use App\Domain\Proposal\Models\Proposal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\Controller;

class NegotiationController extends Controller
{
    /**
     * List negotiations for a company.
     */
    public function index(Request $request)
    {
        $companyId = $request->query('company_id');
        if (!$companyId) {
            return response()->json(['message' => 'Company ID is required.'], 400);
        }

        // Fetch negotiations where the vendor's proposal is involved
        $negotiations = Negotiation::with(['proposal.rfq', 'proposal.company', 'items.proposalItem.rfqItem.catalogue'])
            ->whereHas('proposal', function ($query) use ($companyId) {
                $query->where('company_id', $companyId);
            })
            ->orWhere('buyer_id', Auth::id()) // Or buyer sees their own
            ->latest()
            ->get();

        return response()->json($negotiations);
    }

    /**
     * Buyer submits a negotiation.
     */
    public function store(Request $request, CreateNegotiationAction $action): JsonResponse
    {
        $request->validate([
            'proposal_id' => 'required|exists:proposals,id',
            'payment_scheme' => 'nullable|string',
            'delivery_terms' => 'nullable|string',
            'buyer_remarks' => 'nullable|string',
            'items' => 'required|array',
            'items.*.proposal_item_id' => 'required',
            'items.*.negotiated_price' => 'required|numeric',
            'items.*.negotiated_qty' => 'required|integer',
        ]);

        $proposal = Proposal::with('rfq', 'company.users')->findOrFail($request->proposal_id);
        
        return response()->json([
            'message' => 'Negotiation submitted successfully.',
            'negotiation' => $action->execute($proposal, $request->all())
        ]);
    }

    /**
     * Vendor responds to negotiation (Accept/Decline).
     */
    public function respond(Request $request, Negotiation $negotiation, RespondToNegotiationAction $action): JsonResponse
    {
        $request->validate([
            'status' => 'required|in:accepted,declined',
            'vendor_remarks' => 'nullable|string',
        ]);

        return response()->json([
            'message' => 'Negotiation status updated to ' . $request->status,
            'negotiation' => $action->execute($negotiation, $request->status, $request->vendor_remarks)
        ]);
    }

    /**
     * Show negotiation by proposal.
     */
    public function showByProposal(Proposal $proposal): JsonResponse
    {
        $negotiation = Negotiation::with(['items.proposalItem.rfqItem.catalogue'])
            ->where('proposal_id', $proposal->id)
            ->latest()
            ->first();

        return response()->json(['negotiation' => $negotiation]);
    }
}
