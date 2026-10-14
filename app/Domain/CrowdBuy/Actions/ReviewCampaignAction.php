<?php

namespace App\Domain\CrowdBuy\Actions;

use App\Domain\Auth\Models\User;
use App\Domain\CrowdBuy\Models\CrowdBuyCampaign;
use App\Domain\CrowdBuy\Repositories\CrowdBuyRepositoryInterface;
use App\Domain\Communication\Actions\BroadcastWebsocketNotificationAction;

class ReviewCampaignAction
{
    public function __construct(
        protected CrowdBuyRepositoryInterface $repository,
        protected BroadcastWebsocketNotificationAction $broadcast
    ) {}

    public function execute(CrowdBuyCampaign $campaign, User $reviewer, string $decision, ?string $notes = null): CrowdBuyCampaign
    {
        if ($campaign->status !== 'pending_review') {
            throw new \DomainException('Hanya campaign yang sedang dalam status pending_review yang dapat direview.');
        }

        $oldStatus = $campaign->status;

        if ($decision === 'approve') {
            // If starts_at is in future, scheduled; else active
            $newStatus = $campaign->starts_at->isFuture() ? 'scheduled' : 'active';
            $actionText = 'disetujui';
        } elseif ($decision === 'reject') {
            $newStatus = 'draft'; // return to draft with review notes for revision
            $actionText = 'ditolak / perlu revisi';
        } else {
            throw new \InvalidArgumentException('Keputusan review harus "approve" atau "reject".');
        }

        $updated = $this->repository->updateCampaign($campaign, [
            'status' => $newStatus,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'review_notes' => $notes,
        ]);

        $this->repository->recordHistory(
            $updated,
            $reviewer->id,
            $oldStatus,
            $newStatus,
            "Admin {$actionText}: " . ($notes ?? 'Tanpa catatan tambahan')
        );

        // Notify campaign owner
        try {
            $this->broadcast->execute(
                "Campaign {$actionText}",
                "Campaign Crowd Buy Anda '{$campaign->title}' telah {$actionText} oleh admin.",
                'test-channel',
                true,
                $campaign->created_by,
                "/crowd-buy"
            );
        } catch (\Throwable $e) {
            // Ignore broadcasting failure
        }

        return $updated;
    }
}
