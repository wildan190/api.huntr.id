<?php

namespace App\Domain\Rfq\Actions;

use App\Domain\Rfq\Repositories\RfqRepositoryInterface;
use App\Domain\Rfq\Models\Rfq;
use App\Domain\Auth\Models\User;
use Illuminate\Validation\UnauthorizedException;
use App\Domain\Communication\Actions\BroadcastWebsocketNotificationAction;

class ApproveRfqAction
{
    public function __construct(
        private readonly RfqRepositoryInterface $rfqRepository,
        private readonly BroadcastWebsocketNotificationAction $broadcastAction,
        private readonly NotifyRelevantVendorsAction $notifyVendorsAction
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

        if (!$manager->hasRole('manager') && !$isOwner) {
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
