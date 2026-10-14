<?php

namespace App\Domain\CrowdBuy\Actions;

use App\Domain\Auth\Models\User;
use App\Domain\Company\Models\Company;
use App\Domain\CrowdBuy\Models\CrowdBuyBid;
use App\Domain\CrowdBuy\Models\CrowdBuyCampaign;

class SubmitSupplierBidAction
{
    public function execute(User $user, string $campaignId, array $data): CrowdBuyBid
    {
        if (empty($user->company_id)) {
            throw new \DomainException('Pengguna harus terdaftar pada perusahaan supplier.');
        }

        $company = Company::findOrFail($user->company_id);
        if ($company->type !== 'vendor' || $company->status !== 'approved') {
            throw new \DomainException('Hanya supplier terverifikasi (status approved) yang dapat mengajukan penawaran.');
        }

        $campaign = CrowdBuyCampaign::findOrFail($campaignId);

        if ($campaign->campaign_type !== 'collective_rfq') {
            throw new \DomainException('Penawaran hanya dapat diajukan untuk Collective RFQ.');
        }

        if ($campaign->status !== 'bidding') {
            throw new \DomainException('Collective RFQ ini sudah tidak dalam tahap penerimaan penawaran (bidding).');
        }

        if (now()->greaterThanOrEqualTo($campaign->ends_at)) {
            throw new \DomainException('Batas waktu pengajuan penawaran untuk Collective RFQ ini telah berakhir.');
        }

        // Check if supplier already submitted a pending bid
        $existingBid = CrowdBuyBid::where('campaign_id', $campaignId)
            ->where('supplier_company_id', $user->company_id)
            ->where('status', 'pending')
            ->first();

        if ($existingBid) {
            $existingBid->update([
                'supplier_user_id' => $user->id,
                'offered_unit_price' => (float) $data['offered_unit_price'],
                'min_order_qty' => isset($data['min_order_qty']) ? (int) $data['min_order_qty'] : 1,
                'lead_time_days' => isset($data['lead_time_days']) ? (int) $data['lead_time_days'] : null,
                'notes' => $data['notes'] ?? null,
            ]);

            return $existingBid->fresh(['supplierCompany', 'supplierUser']);
        }

        $bid = CrowdBuyBid::create([
            'campaign_id' => $campaignId,
            'supplier_company_id' => $user->company_id,
            'supplier_user_id' => $user->id,
            'offered_unit_price' => (float) $data['offered_unit_price'],
            'min_order_qty' => isset($data['min_order_qty']) ? (int) $data['min_order_qty'] : 1,
            'lead_time_days' => isset($data['lead_time_days']) ? (int) $data['lead_time_days'] : null,
            'notes' => $data['notes'] ?? null,
            'status' => 'pending',
        ]);

        return $bid->fresh(['supplierCompany', 'supplierUser']);
    }
}
