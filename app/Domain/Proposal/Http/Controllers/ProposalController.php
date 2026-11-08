<?php

namespace App\Domain\Proposal\Http\Controllers;

use App\Domain\Proposal\Actions\SubmitProposalAction;
use App\Domain\Proposal\Actions\CalculateVendorRankingAction;
use App\Domain\Proposal\Actions\GetVendorMyRankAction;
use App\Domain\Proposal\Actions\AwardWinnerAction;
use App\Domain\Proposal\Actions\AwardMultiWinnersAction;
use App\Domain\Proposal\Actions\ApproveWinnerAction;
use App\Domain\Proposal\Actions\CancelAwardAction;
use App\Domain\Proposal\Actions\GetAwaitingApprovalsAction;
use App\Domain\Proposal\Http\Requests\SubmitProposalRequest;
use App\Domain\Proposal\Models\Proposal;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\Domain\Company\Models\Company;
use App\Domain\Company\Services\CompanyAccessService;
use App\Domain\Rfq\Models\Rfq;
use Illuminate\Http\Request;

class ProposalController extends \App\Http\Controllers\Controller
{
    /**
     * Get list of proposals for a company.
     */
    public function index(Request $request, CompanyAccessService $access): JsonResponse
    {
        $companyId = $request->query('company_id');
        $search = $request->query('search');

        if (!$companyId) {
            return response()->json(['message' => 'Company ID is required.'], 400);
        }

        $company = Company::findOrFail($companyId);
        $access->assertMember($request->user(), $company);

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

    public function store(SubmitProposalRequest $request, SubmitProposalAction $action, CompanyAccessService $access): JsonResponse
    {
        $company = Company::findOrFail($request->input('company_id'));
        $access->assertMember($request->user(), $company);
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
    public function calculateRankings(Request $request, Rfq $rfq, CalculateVendorRankingAction $action, CompanyAccessService $access): JsonResponse
    {
        $buyer = Company::query()->findOrFail($rfq->company_id);
        $viewer = $request->filled('company_id')
            ? Company::query()->findOrFail($request->query('company_id'))
            : null;
        if ($viewer) {
            $access->assertMember($request->user(), $viewer);
        }
        $isBuyerViewer = $viewer
            ? (string) $viewer->id === (string) $buyer->id
            : $access->isMember($request->user(), $buyer);
        $isVendorViewer = $viewer?->type === 'vendor';
        $hasSubmittedProposal = $isVendorViewer && Proposal::query()
            ->where('rfq_id', $rfq->id)
            ->where('company_id', $viewer->id)
            ->exists();
        $isPublicVendorRfq = $isVendorViewer && in_array($rfq->status, ['active', 'open'], true);
        abort_unless($isBuyerViewer || $hasSubmittedProposal || $isPublicVendorRfq, 403, 'Anda tidak memiliki akses ke peringkat RFQ ini.');

        $rankings = $action->execute($rfq);
        if (! $isBuyerViewer && $isVendorViewer) {
            $rankings = array_values(array_filter(
                $rankings,
                fn (array $ranking) => (string) ($ranking['proposal']->company_id ?? '') === (string) $viewer->id,
            ));
        }

        return response()->json(['rankings' => $rankings]);
    }

    /**
     * Get overview rankings for a vendor (My Rank page).
     */
    public function vendorRankings(Request $request, GetVendorMyRankAction $action, CompanyAccessService $access): JsonResponse
    {
        $companyId = $request->query('company_id');
        if (!$companyId) {
            return response()->json(['error' => 'Company ID is required'], 400);
        }

        $company = Company::findOrFail($companyId);
        $access->assertMember($request->user(), $company);
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

            // Demo Mode: run AFTER transaction commits — auto approve winner, generate PO, confirm PO
            if (config('app.demo_mode', false)) {
                try {
                    $approvedProposal = app(ApproveWinnerAction::class)->execute($awardedProposal->fresh(), $userId);
                    if (!empty($approvedProposal->generatedPurchaseOrder)) {
                        app(\App\Domain\AI\Services\DemoBotService::class)->handleBotConfirmPo($approvedProposal->generatedPurchaseOrder);
                    }
                } catch (\Exception $e) {
                    Log::warning("Demo auto approve+confirm failed: " . $e->getMessage());
                }
            }

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
     * Buyer awards multiple proposals as winners (Split Award: 2-5 vendors).
     */
    public function awardWinners(Request $request, Rfq $rfq, AwardMultiWinnersAction $action): JsonResponse
    {
        try {
            $userId = Auth::id() ?: $request->input('user_id');
            if (!$userId) {
                return response()->json(['message' => 'User ID is required for awarding.'], 401);
            }

            $awards = $request->input('awards', []);
            $awardMode = $request->input('award_mode', 'separate_order');
            $generatePo = $request->boolean('generate_po', true);

            if (!is_array($awards) || empty($awards)) {
                return response()->json(['message' => 'Daftar pemenang (awards) wajib diisi.'], 422);
            }

            $awardedProposals = $action->execute($rfq, $userId, $awards, $awardMode, $generatePo);

            $pos = \App\Domain\Order\Models\PurchaseOrder::where('rfq_id', $rfq->id)
                ->whereIn('proposal_id', collect($awards)->pluck('proposal_id')->all())
                ->get();

            return response()->json([
                'message' => 'Pemenang tender dan Purchase Order berhasil ditetapkan.',
                'rfq' => $rfq->fresh(['company']),
                'proposals' => $awardedProposals,
                'purchase_orders' => $pos,
            ]);
        } catch (\Illuminate\Validation\ValidationException $ve) {
            return response()->json([
                'message' => $ve->getMessage(),
                'errors' => $ve->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error in awardWinners', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'rfq_id' => $rfq->id,
                'request' => $request->all(),
            ]);
            return response()->json([
                'message' => 'Error menetapkan pemenang tender: ' . $e->getMessage()
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

        // Demo Mode: auto-confirm PO after transaction commits
        if (config('app.demo_mode', false)) {
            try {
                if (!empty($approvedProposal->generatedPurchaseOrder)) {
                    app(\App\Domain\AI\Services\DemoBotService::class)->handleBotConfirmPo($approvedProposal->generatedPurchaseOrder);
                }
            } catch (\Exception $e) {
                Log::warning("Demo PO auto-confirm failed: " . $e->getMessage());
            }
        }

        return response()->json([
            'message' => $approvedProposal->getAttribute('approval_pending') ? 'Winner approval recorded. Waiting for the next approval stage.' : 'Winner approved successfully.',
            'proposal' => $approvedProposal->load('rfq', 'company'),
        ]);
    }

    /**
     * Get proposal detail with full offer information.
     */
    public function show(Request $request, Proposal $proposal, CompanyAccessService $access): JsonResponse
    {
        $proposal->loadMissing('rfq');
        $buyer = Company::query()->findOrFail($proposal->rfq->company_id);
        $vendor = Company::query()->findOrFail($proposal->company_id);
        abort_unless($access->isMember($request->user(), $buyer) || $access->isMember($request->user(), $vendor), 403);

        return response()->json(
            $proposal->load('rfq.items.catalogue', 'company', 'items.rfqItem.catalogue')
        );
    }

    /**
     * Get all proposals awaiting manager approval.
     */
    public function awaitingApproval(Request $request, GetAwaitingApprovalsAction $action, CompanyAccessService $access): JsonResponse
    {
        $companyId = $request->query('company_id');
        $company = Company::query()->findOrFail($companyId);
        $access->assertCan($request->user(), $company, 'procurement.winner.approve');
        $proposals = $action->execute($companyId);
        return response()->json(['proposals' => $proposals]);
    }

    /**
     * Manager/Approver cancels the awarded winner status.
     */
    public function cancelAward(Request $request, Rfq $rfq, CancelAwardAction $action): JsonResponse
    {
        $userId = Auth::id() ?: $request->input('user_id');

        if (!$userId) {
            return response()->json(['message' => 'User ID is required.'], 401);
        }

        $reason = $request->input('reason');
        if (empty($reason)) {
            return response()->json(['message' => 'Alasan pembatalan penetapan pemenang wajib diisi.'], 422);
        }

        try {
            $updatedRfq = $action->execute($rfq, $userId, $reason);

            return response()->json([
                'success' => true,
                'message' => 'Penetapan pemenang tender berhasil dibatalkan. Status tender dikembalikan ke aktif.',
                'rfq' => $updatedRfq,
            ]);
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            Log::error('CancelAward failed: ' . $e->getMessage(), [
                'rfq_id' => $rfq->id,
                'user_id' => $userId,
                'reason' => $reason,
            ]);
            return response()->json(['message' => 'Gagal membatalkan penetapan pemenang: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Print Vendor Quotation document (HTML view for Ctrl+P / print preview / audit trail).
     */
    public function printQuotation(Request $request, Proposal $proposal, CompanyAccessService $access)
    {
        $proposal->loadMissing(['rfq.items.catalogue', 'rfq.company', 'company', 'items.rfqItem.catalogue']);
        $rfq = $proposal->rfq;
        $buyerCompany = $rfq?->company;
        $vendorCompany = $proposal->company;

        $user = $request->user();
        if ($user) {
            $isBuyerMember = $buyerCompany && $access->isMember($user, $buyerCompany);
            $isVendorMember = $vendorCompany && $access->isMember($user, $vendorCompany);
            if (!$isBuyerMember && !$isVendorMember && (string) $user->role !== 'admin') {
                abort(403, 'Unauthorized access to quotation document.');
            }
        }

        $rfqItems = $rfq ? $rfq->items->keyBy('id') : collect();
        $itemsData = [];
        $totalAmount = 0;

        foreach ($proposal->items as $pItem) {
            $rfqItem = $rfqItems->get($pItem->rfq_item_id);
            $qty = $rfqItem ? (float) $rfqItem->qty : 1;
            $unitPrice = (float) $pItem->price_offer;
            $subtotal = $qty * $unitPrice;
            $totalAmount += $subtotal;

            $itemsData[] = [
                'name' => $rfqItem?->catalogue?->name ?? $rfqItem?->item_name ?? 'Barang / Jasa',
                'description' => $rfqItem?->catalogue?->description ?? $rfqItem?->description ?? null,
                'code' => $rfqItem?->catalogue?->item_code ?? $rfqItem?->item_code ?? null,
                'qty' => $qty,
                'uom' => $rfqItem?->catalogue?->uom ?? $rfqItem?->uom ?? 'unit',
                'unit_price' => $unitPrice,
                'total_price' => $subtotal,
            ];
        }

        if (empty($itemsData)) {
            $itemsData[] = [
                'name' => $rfq?->title ?? 'Pengadaan',
                'description' => 'Penawaran paket pekerjaan',
                'code' => null,
                'qty' => 1,
                'uom' => 'paket',
                'unit_price' => (float) $proposal->price_offer,
                'total_price' => (float) $proposal->price_offer,
            ];
            $totalAmount = (float) $proposal->price_offer;
        }

        $quotationData = [
            'quotation_number' => $proposal->quotation_number,
            'date' => $proposal->created_at ? $proposal->created_at->format('d/m/Y') : date('d/m/Y'),
            'submitted_at' => $proposal->created_at ? $proposal->created_at->format('d M Y, H:i') . ' WIB' : date('d M Y, H:i') . ' WIB',
            'delivery_days' => $proposal->delivery_days,
            'warranty_months' => $proposal->warranty_months,
            'payment_term' => $proposal->payment_term,
            'rfq_id' => $rfq?->id,
            'rfq_title' => $rfq?->title,
            'rfq_procurement_type' => $rfq?->procurement_type,
            'vendor_name' => $vendorCompany?->name ?? 'Vendor',
            'vendor_address' => $vendorCompany?->address ?? null,
            'vendor_logo_url' => $vendorCompany?->logo_url ?? null,
            'vendor_tax_id' => $vendorCompany?->tax_id ?? null,
            'buyer_name' => $buyerCompany?->name ?? 'Buyer',
            'buyer_address' => $buyerCompany?->address ?? null,
            'buyer_logo_url' => $buyerCompany?->logo_url ?? null,
            'buyer_tax_id' => $buyerCompany?->tax_id ?? null,
            'items' => $itemsData,
            'total_amount' => $totalAmount,
            'awarded_amount' => (float) $proposal->awarded_amount,
            'winner_status' => $proposal->winner_status,
        ];

        return view('print.vendor_quotation', ['quotation' => $quotationData]);
    }
}
