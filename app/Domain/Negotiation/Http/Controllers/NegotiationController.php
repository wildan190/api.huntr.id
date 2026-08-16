<?php

namespace App\Domain\Negotiation\Http\Controllers;

use App\Domain\Negotiation\Models\Negotiation;
use App\Domain\Negotiation\Actions\CreateNegotiationAction;
use App\Domain\Negotiation\Actions\RespondToNegotiationAction;
use App\Domain\Negotiation\Http\Requests\StoreNegotiationRequest;
use App\Domain\Negotiation\Http\Requests\RespondNegotiationRequest;
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
    public function store(StoreNegotiationRequest $request, CreateNegotiationAction $action): JsonResponse
    {
        $proposal = Proposal::with('rfq', 'company.users')->findOrFail($request->proposal_id);
        $negotiation = $action->execute($proposal, $request->validated());

        // Demo Mode: AI Bot auto-responds AFTER the transaction commits
        if (config('app.demo_mode', false)) {
            try {
                app(\App\Domain\AI\Services\DemoBotService::class)->handleBotNegotiation($negotiation);
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::warning("DemoBotService negotiation auto-response failed: " . $e->getMessage());
            }
            // Re-fetch the updated negotiation so the response reflects accepted status
            $negotiation = $negotiation->fresh('items.proposalItem.rfqItem.catalogue');
        }

        return response()->json([
            'message' => 'Negotiation submitted successfully.',
            'negotiation' => $negotiation
        ]);
    }

    /**
     * Vendor responds to negotiation (Accept/Decline).
     */
    public function respond(RespondNegotiationRequest $request, Negotiation $negotiation, RespondToNegotiationAction $action): JsonResponse
    {
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
