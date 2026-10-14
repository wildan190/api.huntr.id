<?php

namespace App\Domain\CrowdBuy\Actions;

use App\Domain\Auth\Models\User;
use App\Domain\CrowdBuy\Models\CrowdBuyBid;
use App\Domain\CrowdBuy\Models\CrowdBuyCampaign;
use App\Domain\CrowdBuy\Repositories\CrowdBuyRepositoryInterface;
use Illuminate\Support\Facades\DB;

class AcceptSupplierBidAction
{
    public function __construct(
        protected CrowdBuyRepositoryInterface $repository
    ) {}

    public function execute(User $user, string $campaignId, string $bidId): CrowdBuyCampaign
    {
        return DB::transaction(function () use ($user, $campaignId, $bidId) {
            /** @var CrowdBuyCampaign $campaign */
            $campaign = CrowdBuyCampaign::where('id', $campaignId)->lockForUpdate()->firstOrFail();

            // Authorization: must be the creator buyer company or admin
            $isInitiator = $campaign->initiator_company_id === $user->company_id;
            $isAdmin = $user->hasRole('admin');

            if (!$isInitiator && !$isAdmin) {
                throw new \DomainException('Hanya perusahaan inisiator Collective RFQ yang dapat menerima penawaran supplier.');
            }

            if ($campaign->campaign_type !== 'collective_rfq') {
                throw new \DomainException('Aksi ini hanya berlaku untuk Collective RFQ.');
            }

            /** @var CrowdBuyBid $chosenBid */
            $chosenBid = CrowdBuyBid::with('supplierCompany')->where('id', $bidId)->where('campaign_id', $campaignId)->firstOrFail();

            if ($chosenBid->status !== 'pending') {
                throw new \DomainException('Penawaran ini sudah tidak berstatus pending.');
            }

            // Accept chosen bid
            $chosenBid->update(['status' => 'accepted']);

            // Reject all other pending bids
            CrowdBuyBid::where('campaign_id', $campaignId)
                ->where('id', '!=', $bidId)
                ->where('status', 'pending')
                ->update(['status' => 'rejected']);

            // Update campaign with winning supplier & price, activate campaign
            $campaign->update([
                'supplier_company_id' => $chosenBid->supplier_company_id,
                'base_price' => $chosenBid->offered_unit_price,
                'lead_time_days' => $chosenBid->lead_time_days ?? $campaign->lead_time_days,
                'status' => 'active', // Menjadi active agar peserta bisa lanjut transaksi/checkout
            ]);

            $supplierName = $chosenBid->supplierCompany->name ?? 'Supplier';
            $formattedPrice = number_format($chosenBid->offered_unit_price, 0, ',', '.');

            $this->repository->recordHistory(
                $campaign,
                $user->id,
                'bidding',
                'active',
                "Penawaran dari {$supplierName} diterima (Rp {$formattedPrice} per {$campaign->unit}). Status campaign menjadi aktif."
            );

            return $campaign->fresh(['supplier', 'initiatorCompany', 'priceTiers', 'bids.supplierCompany']);
        });
    }
}
