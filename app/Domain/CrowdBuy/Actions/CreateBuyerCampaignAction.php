<?php

namespace App\Domain\CrowdBuy\Actions;

use App\Domain\Auth\Models\User;
use App\Domain\Company\Models\Company;
use App\Domain\CrowdBuy\Models\CrowdBuyCampaign;
use App\Domain\CrowdBuy\Models\CrowdBuyOrder;
use App\Domain\CrowdBuy\Repositories\CrowdBuyRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateBuyerCampaignAction
{
    public function __construct(
        protected CrowdBuyRepositoryInterface $repository
    ) {}

    public function execute(User $user, array $data): CrowdBuyCampaign
    {
        if (empty($user->company_id)) {
            throw new \DomainException('Pengguna harus terdaftar pada perusahaan pembeli (buyer company).');
        }

        $campaignType = $data['campaign_type'] ?? 'buyer_catalog';
        if (!in_array($campaignType, ['buyer_catalog', 'collective_rfq'])) {
            throw new \DomainException('Tipe campaign buyer tidak valid. Pilih buyer_catalog atau collective_rfq.');
        }

        return DB::transaction(function () use ($user, $data, $campaignType) {
            $dateStr = now()->format('Ymd');
            $random = strtoupper(Str::random(4));
            $inviteCode = strtoupper(Str::random(8));

            if ($campaignType === 'buyer_catalog') {
                if (empty($data['supplier_company_id'])) {
                    throw new \DomainException('Supplier wajib dipilih untuk inisiasi Crowd Buy dari katalog.');
                }

                $supplier = Company::find($data['supplier_company_id']);
                if (!$supplier) {
                    throw new \DomainException('Supplier tidak ditemukan.');
                }

                $code = "CBC-GRP-{$dateStr}-{$random}";
                $status = 'active'; // Langsung aktif agar buyer lain bisa bergabung
                $basePrice = (float) $data['base_price'];
                $supplierCompanyId = $data['supplier_company_id'];
                $targetPrice = null;
            } else {
                // collective_rfq (Opsi 2: Permintaan Kolektif Custom Spec)
                $code = "CRFQ-{$dateStr}-{$random}";
                $status = 'bidding'; // Menunggu penawaran dari para supplier terdaftar
                $supplierCompanyId = null;
                $targetPrice = isset($data['target_price']) ? (float) $data['target_price'] : 0.0;
                $basePrice = $targetPrice;
            }

            $campaignData = [
                'code' => $code,
                'creator_type' => 'buyer',
                'campaign_type' => $campaignType,
                'initiator_company_id' => $user->company_id,
                'invite_code' => $inviteCode,
                'supplier_company_id' => $supplierCompanyId,
                'created_by' => $user->id,
                'catalogue_id' => $data['catalogue_id'] ?? null,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'category' => $data['category'] ?? 'General',
                'unit' => $data['unit'] ?? 'pcs',
                'base_price' => $basePrice,
                'target_price' => $targetPrice,
                'target_quantity' => (int) $data['target_quantity'],
                'max_quantity' => isset($data['max_quantity']) ? (int) $data['max_quantity'] : null,
                'min_order_qty' => isset($data['min_order_qty']) ? (int) $data['min_order_qty'] : 1,
                'max_order_qty' => isset($data['max_order_qty']) ? (int) $data['max_order_qty'] : null,
                'current_quantity' => 0,
                'status' => $status,
                'starts_at' => now(),
                'ends_at' => $data['ends_at'],
                'lead_time_days' => isset($data['lead_time_days']) ? (int) $data['lead_time_days'] : 7,
                'delivery_terms' => $data['delivery_terms'] ?? null,
                'return_policy' => $data['return_policy'] ?? null,
                'image_path' => $data['image_path'] ?? null,
                'price_tiers' => $data['price_tiers'] ?? [],
            ];

            $campaign = $this->repository->createCampaign($campaignData);

            $this->repository->recordHistory(
                $campaign,
                $user->id,
                null,
                $status,
                $campaignType === 'buyer_catalog'
                    ? "Inisiasi Crowd Buy dibuka oleh buyer ({$code})"
                    : "Collective RFQ / Demand Pool dibuat oleh buyer ({$code})"
            );

            // Optional: Jika buyer inisiator langsung memasukkan kuantiti pesanan pertama
            $initialQty = isset($data['initial_quantity']) ? (int) $data['initial_quantity'] : 0;
            if ($initialQty > 0) {
                $shippingAddress = $data['initial_shipping_address'] ?? [
                    'recipient_name' => $user->name,
                    'phone' => $user->phone ?? '-',
                    'address_line' => 'Alamat Perusahaan Inisiator',
                    'city' => '-',
                    'province' => '-',
                    'postal_code' => '-',
                ];

                $unitPrice = $campaign->getEffectiveUnitPrice($initialQty);
                $subtotal = round($unitPrice * $initialQty, 2);

                $orderDate = now()->format('Ymd');
                $orderRandom = strtoupper(Str::random(5));
                $orderNumber = "CBO-{$orderDate}-{$orderRandom}";

                $order = CrowdBuyOrder::create([
                    'order_number' => $orderNumber,
                    'campaign_id' => $campaign->id,
                    'buyer_company_id' => $user->company_id,
                    'user_id' => $user->id,
                    'quantity' => $initialQty,
                    'unit_price' => $unitPrice,
                    'subtotal' => $subtotal,
                    'tax_amount' => 0,
                    'shipping_cost' => 0,
                    'total_amount' => $subtotal,
                    'shipping_address' => $shippingAddress,
                    'status' => $campaignType === 'collective_rfq' ? 'pending_payment' : 'pending_payment',
                    'payment_deadline' => now()->addHours(24),
                    'notes' => 'Pemesanan awal oleh inisiator group buy',
                ]);

                $campaign->increment('current_quantity', $initialQty);
            }

            return $campaign->fresh(['supplier', 'initiatorCompany', 'priceTiers']);
        });
    }
}
