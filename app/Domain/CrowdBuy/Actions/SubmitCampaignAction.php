<?php

namespace App\Domain\CrowdBuy\Actions;

use App\Domain\Auth\Models\User;
use App\Domain\CrowdBuy\Models\CrowdBuyCampaign;
use App\Domain\CrowdBuy\Repositories\CrowdBuyRepositoryInterface;
use App\Domain\Communication\Actions\BroadcastWebsocketNotificationAction;

class SubmitCampaignAction
{
    public function __construct(
        protected CrowdBuyRepositoryInterface $repository,
        protected BroadcastWebsocketNotificationAction $broadcast
    ) {}

    public function execute(CrowdBuyCampaign $campaign, User $user): CrowdBuyCampaign
    {
        if ($campaign->status !== 'draft') {
            throw new \DomainException('Hanya campaign dengan status draft yang dapat diajukan untuk review.');
        }

        if ($campaign->supplier_company_id !== $user->company_id && !$user->hasRole('admin')) {
            throw new \DomainException('Anda tidak berwenang mengajukan campaign ini.');
        }

        // Validate basic readiness
        if (!$campaign->title || $campaign->base_price <= 0 || $campaign->target_quantity <= 0) {
            throw new \DomainException('Data campaign belum lengkap (judul, harga, dan target wajib diisi dengan benar).');
        }

        if ($campaign->ends_at <= now()) {
            throw new \DomainException('Batas waktu campaign harus berada di masa depan.');
        }

        $campaign = $this->repository->updateCampaign($campaign, [
            'status' => 'pending_review',
        ]);

        $this->repository->recordHistory(
            $campaign,
            $user->id,
            'draft',
            'pending_review',
            'Campaign submitted for admin review'
        );

        // Notify admins
        try {
            $this->broadcast->execute(
                'Review Campaign Baru',
                "Supplier {$campaign->supplier->name} mengajukan campaign Crowd Buy: {$campaign->title}",
                'admin-channel',
                false,
                null,
                "/admin/crowd-buy"
            );
        } catch (\Throwable $e) {
            // Ignore broadcasting error in test or local without socket
        }

        return $campaign;
    }
}
