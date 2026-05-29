<?php

namespace App\Domain\Order\Actions;

use App\Domain\Order\Repositories\OrderRepositoryInterface;
use App\Domain\Proposal\Repositories\ProposalRepositoryInterface;
use App\Domain\Rfq\Repositories\RfqRepositoryInterface;
use App\Domain\Proposal\Models\Proposal;
use App\Domain\Order\Models\PurchaseOrder;
use App\Domain\Rfq\Models\Rfq;
use App\Domain\Auth\Models\User;
use Illuminate\Validation\UnauthorizedException;

class AwardVendorAction
{
    public function __construct(
        private readonly OrderRepositoryInterface    $orderRepository,
        private readonly RfqRepositoryInterface      $rfqRepository,
        private readonly ProposalRepositoryInterface $proposalRepository
    ) {}

    /**
     * Buyer manager awards an active RFQ tender to a specific vendor proposal.
     *
     * @param User $manager Approving purchasing manager
     * @param Rfq $rfq The RFQ being awarded
     * @param Proposal $winningProposal The awarded vendor proposal
     * @return PurchaseOrder
     * @throws UnauthorizedException
     */
    public function execute(User $manager, Rfq $rfq, Proposal $winningProposal): PurchaseOrder
    {
        if ($manager->role !== 'manager') {
            throw new UnauthorizedException("Only purchasing managers can award RFQs and generate POs.");
        }

        // 1. Mark RFQ as awarded
        $this->rfqRepository->update($rfq, ['status' => 'awarded']);

        // 2. Reject other proposals, accept winning one
        $this->proposalRepository->rejectOthers($rfq, $winningProposal->id);
        $this->proposalRepository->update($winningProposal, ['status' => 'accepted']);

        // 3. Generate Purchase Order
        $poNumber = 'PO-' . date('Ymd') . '-' . str_pad($rfq->id, 4, '0', STR_PAD_LEFT);

        return $this->orderRepository->createPurchaseOrder([
            'rfq_id'    => $rfq->id,
            'vendor_id' => $winningProposal->company_id,
            'po_number' => $poNumber,
            'status'    => 'pending_manager',
        ]);
    }
}
