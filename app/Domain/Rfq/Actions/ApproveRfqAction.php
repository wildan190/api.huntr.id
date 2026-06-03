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

        if ($manager->role !== 'manager' && !$isOwner) {
            throw new UnauthorizedException("Only purchasing managers or company owners can approve RFQs.");
        }

        $rfq = $this->rfqRepository->update($rfq, [
            'status' => 'active',
            'approved_by' => $manager->name,
            'approved_at' => now(),
        ]);

        // Notify the buyer who created the PR
        $this->broadcastAction->execute(
            "PR Approved",
            "PR '{$rfq->title}' has been approved and published.",
            'test-channel',
            true,
            $rfq->user_id,
            "/my-pr"
        );

        // Notify relevant vendors
        $this->notifyVendorsAction->execute($rfq);

        return $rfq;
    }
}
