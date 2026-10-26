<?php

namespace App\Domain\Order\Actions;

use App\Domain\AI\Services\DemoBotService;
use App\Domain\Auth\Models\User;
use App\Domain\Budget\Services\BudgetCommitmentService;
use App\Domain\Company\Services\CompanyAccessService;
use App\Domain\Order\Models\PurchaseOrder;
use App\Domain\Order\Repositories\OrderRepositoryInterface;
use App\Domain\Proposal\Models\Proposal;
use App\Domain\Proposal\Repositories\ProposalRepositoryInterface;
use App\Domain\Rfq\Models\Rfq;
use App\Domain\Rfq\Repositories\RfqRepositoryInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\UnauthorizedException;

class AwardVendorAction
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly RfqRepositoryInterface $rfqRepository,
        private readonly ProposalRepositoryInterface $proposalRepository,
        private readonly BudgetCommitmentService $budgetCommitments,
        private readonly CompanyAccessService $access,
    ) {}

    /**
     * Buyer manager awards an active RFQ tender to a specific vendor proposal.
     *
     * @param  User  $manager  Approving purchasing manager
     * @param  Rfq  $rfq  The RFQ being awarded
     * @param  Proposal  $winningProposal  The awarded vendor proposal
     *
     * @throws UnauthorizedException
     */
    public function execute(User $manager, Rfq $rfq, Proposal $winningProposal): PurchaseOrder
    {
        $rfq->loadMissing('company');
        abort_unless((string) $winningProposal->rfq_id === (string) $rfq->id, 422, 'Proposal does not belong to this RFQ.');
        $this->access->assertCan($manager, $rfq->company, 'procurement.winner.select');

        // 1. Mark RFQ as awarded
        $this->rfqRepository->update($rfq, ['status' => 'awarded']);

        // 2. Reject other proposals, accept winning one
        $this->proposalRepository->rejectOthers($rfq, $winningProposal->id);
        $this->proposalRepository->update($winningProposal, ['status' => 'accepted']);

        // 3. Generate Purchase Order
        $poNumber = 'PO-'.date('Ymd').'-'.str_pad($rfq->id, 4, '0', STR_PAD_LEFT);

        $po = $this->orderRepository->createPurchaseOrder([
            'rfq_id' => $rfq->id,
            'procurement_plan_id' => $rfq->procurement_plan_id,
            'procurement_type' => $rfq->procurement_type ?? 'goods',
            'project_type' => $rfq->project_type ?? null,
            'proposal_id' => $winningProposal->id,
            'vendor_id' => $winningProposal->company_id,
            'po_number' => $poNumber,
            'status' => 'issued',
            'created_by' => $manager->id,
            'buyer_company_id' => $rfq->company_id,
            'total_amount' => $winningProposal->price_offer,
            'purchase_type' => $winningProposal->payment_term,
            'delivery_point' => $rfq->delivery_point,
            'service_start_date' => $rfq->service_start_date,
            'service_end_date' => $rfq->service_end_date,
            'service_location' => $rfq->service_location,
            'acceptance_criteria' => $rfq->acceptance_criteria,
            'requires_service_acceptance' => in_array($rfq->procurement_type ?? 'goods', ['services', 'mixed'], true),
            'department' => $rfq->department ?? 'General Procurement',
        ]);

        $this->budgetCommitments->sync($po);

        // Demo Mode: AI Bot Auto-confirms PO
        if (config('app.demo_mode', false)) {
            try {
                app(DemoBotService::class)->handleBotConfirmPo($po);
            } catch (\Exception $e) {
                Log::warning('DemoBotService PO auto-confirm failed: '.$e->getMessage());
            }
        }

        return $po->fresh();
    }
}
