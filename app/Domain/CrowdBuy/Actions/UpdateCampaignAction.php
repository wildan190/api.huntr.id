<?php

namespace App\Domain\CrowdBuy\Actions;

use App\Domain\Auth\Models\User;
use App\Domain\CrowdBuy\Models\CrowdBuyCampaign;
use App\Domain\CrowdBuy\Repositories\CrowdBuyRepositoryInterface;

class UpdateCampaignAction
{
    public function __construct(
        protected CrowdBuyRepositoryInterface $repository
    ) {}

    public function execute(CrowdBuyCampaign $campaign, User $user, array $data): CrowdBuyCampaign
    {
        // Only draft or pending_review can be updated by supplier
        if (!in_array($campaign->status, ['draft', 'pending_review'])) {
            throw new \DomainException('Campaign yang sudah aktif atau selesai tidak dapat diubah sembarangan.');
        }

        // Supplier ownership check
        if ($campaign->supplier_company_id !== $user->company_id && !$user->hasRole('admin')) {
            throw new \DomainException('Anda tidak memiliki akses untuk mengubah campaign ini.');
        }

        if (isset($data['target_quantity']) && isset($data['max_quantity']) && $data['max_quantity'] !== null) {
            if ($data['max_quantity'] < $data['target_quantity']) {
                throw new \DomainException('Batas maksimum kuantitas harus lebih besar atau sama dengan target minimum.');
            }
        }

        $oldStatus = $campaign->status;
        $updated = $this->repository->updateCampaign($campaign, $data);

        $this->repository->recordHistory(
            $updated,
            $user->id,
            $oldStatus,
            $updated->status,
            'Campaign details updated'
        );

        return $updated;
    }
}
