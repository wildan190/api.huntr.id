<?php

namespace App\Domain\Rfq\Actions;

use App\Domain\Rfq\Repositories\RfqRepositoryInterface;
use App\Domain\Company\Services\CompanyAccessService;
use App\Domain\Rfq\Models\Rfq;
use App\Domain\Auth\Models\User;
use Illuminate\Validation\UnauthorizedException;
use App\Domain\Communication\Actions\BroadcastWebsocketNotificationAction;

class ApproveRfqAction
{
    public function __construct(
        private readonly RfqRepositoryInterface $rfqRepository,
        private readonly BroadcastWebsocketNotificationAction $broadcastAction,
        private readonly NotifyRelevantVendorsAction $notifyVendorsAction,
        private readonly CompanyAccessService $companyAccess,
    ) {}

    /**
     * Buyer Manager approves Purchase Request RFQ and lists it on Global RFQs.
     *
     * @param User $manager The approving manager user
     * @param Rfq $rfq The target RFQ
     * @return Rfq
     * @throws UnauthorizedException
     */
    public function execute(User $manager, Rfq $rfq): Rfq
    {
        $isOwner = $rfq->company->owner_id === $manager->id;

        $estimatedAmount = $rfq->items()->get()->sum(fn ($item) => (float) $item->qty * (float) ($item->estimated_price ?? 0));
        $approval = $this->companyAccess->approveSubject(
            $rfq->company,
            'purchase_request',
            $rfq->id,
            $manager,
            $estimatedAmount,
            $rfq->user_id,
            ['title' => $rfq->title, 'procurement_mode' => $rfq->procurement_mode],
        );
        if ($approval['configured'] && ! $approval['completed']) {
            $rfq->setAttribute('approval_pending', true);
            $rfq->setAttribute('approval_request', $approval['request']);
            return $rfq;
        }

        if (! $approval['configured'] && ! $isOwner && ! $this->companyAccess->can($manager, $rfq->company, 'procurement.pr.approve')) {
            throw new UnauthorizedException("Only purchasing managers or company owners can approve RFQs.");
        }

        $isDirectPurchase = $rfq->procurement_mode === 'direct';
        $rfq = $this->rfqRepository->update($rfq, [
            'status' => $isDirectPurchase ? 'approved' : 'active',
            'approved_by' => $manager->name,
            'approved_at' => now(),
        ]);

        // Notify the buyer who created the PR
        $this->broadcastAction->execute(
            "PR Approved",
            $isDirectPurchase ? "PR '{$rfq->title}' has been approved for direct purchase." : "PR '{$rfq->title}' has been approved and published.",
            'test-channel',
            true,
            $rfq->user_id,
            "/my-pr",
            ['type' => 'pr_approved']
        );

        if ($isDirectPurchase) {
            return $rfq;
        }

        // Notify relevant vendors only for a tender.
        $this->notifyVendorsAction->execute($rfq);

        // Demo Mode: Trigger 5 AI Vendor Bots
        if (config('app.demo_mode', false)) {
            try {
                app(\App\Domain\AI\Services\DemoBotService::class)->generateFiveVendorBotsForRfq($rfq);
            } catch (\Exception $e) {
                Log::warning("DemoBotService auto-trigger failed: " . $e->getMessage());
            }
        }

        return $rfq;
    }
}
