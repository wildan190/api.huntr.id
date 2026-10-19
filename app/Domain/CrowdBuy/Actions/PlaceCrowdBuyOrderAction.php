<?php

namespace App\Domain\CrowdBuy\Actions;

use App\Domain\Auth\Models\User;
use App\Domain\CrowdBuy\Models\CrowdBuyCampaign;
use App\Domain\CrowdBuy\Models\CrowdBuyOrder;
use App\Domain\CrowdBuy\Repositories\CrowdBuyRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PlaceCrowdBuyOrderAction
{
    public function __construct(
        protected CrowdBuyRepositoryInterface $repository
    ) {}

    public function execute(
        User $user,
        string $campaignId,
        int $quantity,
        array $shippingAddress,
        ?string $notes = null,
        bool $isMonthlyCommitment = false,
        ?int $monthlyCommitmentQty = null,
        ?string $deliveryPoint = null
    ): CrowdBuyOrder
    {
        if (empty($user->company_id)) {
            throw new \DomainException('Pengguna harus terasosiasi dengan perusahaan pembeli (buyer company) untuk membuat order.');
        }

        return DB::transaction(function () use ($user, $campaignId, $quantity, $shippingAddress, $notes, $isMonthlyCommitment, $monthlyCommitmentQty, $deliveryPoint) {
            // Lock campaign record for concurrency protection
            /** @var CrowdBuyCampaign $campaign */
            $campaign = CrowdBuyCampaign::where('id', $campaignId)->lockForUpdate()->firstOrFail();

            // 1. Verify campaign state
            if ($campaign->status !== 'active') {
                throw new \DomainException('Campaign ini sedang tidak aktif dan tidak menerima order.');
            }

            if (now()->lessThan($campaign->starts_at)) {
                throw new \DomainException('Campaign ini belum dimulai.');
            }

            if (now()->greaterThanOrEqualTo($campaign->ends_at)) {
                throw new \DomainException('Batas waktu campaign ini telah berakhir.');
            }

            // 2. Prevent initiator from joining their own campaign (self-dealing prevention)
            if ($campaign->initiator_company_id && $user->company_id === $campaign->initiator_company_id) {
                throw new \DomainException('Anda adalah inisiator campaign ini dan tidak dapat bergabung sebagai pembeli. Initial quantity Anda sudah terhitung dalam progress campaign.');
            }

            // 3. Validate quantity limits
            if ($quantity <= 0) {
                throw new \DomainException('Kuantitas order harus lebih besar dari 0.');
            }

            if ($quantity < $campaign->min_order_qty) {
                throw new \DomainException("Kuantitas minimum pemesanan (MOQ) adalah {$campaign->min_order_qty} {$campaign->unit}.");
            }

            if ($campaign->max_order_qty && $quantity > $campaign->max_order_qty) {
                throw new \DomainException("Kuantitas maksimum per pemesan adalah {$campaign->max_order_qty} {$campaign->unit}.");
            }

            // 4. Concurrency-safe capacity check
            if ($campaign->max_quantity !== null) {
                $availableCapacity = $campaign->max_quantity - $campaign->current_quantity;
                if ($quantity > $availableCapacity) {
                    throw new \DomainException("Kapasitas tersisa hanya {$availableCapacity} {$campaign->unit}. Tidak dapat memesan {$quantity} {$campaign->unit}.");
                }
            }

            // 5. Calculate authoritative price snapshot
            $unitPrice = $campaign->getEffectiveUnitPrice($quantity);
            $subtotal = round($unitPrice * $quantity, 2);
            $taxAmount = 0.0; // PPN can be configured or 0
            $shippingCost = 0.0;
            $totalAmount = $subtotal + $taxAmount + $shippingCost;

            // 6. Unique Order Number
            $dateStr = now()->format('Ymd');
            $random = strtoupper(Str::random(5));
            $orderNumber = "CBO-{$dateStr}-{$random}";

            // Payment deadline: 24h from now or campaign ends_at, whichever is earlier
            $paymentDeadline = min(now()->addHours(24), $campaign->ends_at);

            $resolvedDeliveryPoint = $deliveryPoint ?: ($shippingAddress['address'] ?? null);

            // 7. Persist order
            $orderData = [
                'order_number' => $orderNumber,
                'campaign_id' => $campaign->id,
                'buyer_company_id' => $user->company_id,
                'user_id' => $user->id,
                'quantity' => $quantity,
                'is_monthly_commitment' => $isMonthlyCommitment || (bool) $campaign->is_monthly_recurring,
                'monthly_commitment_qty' => $monthlyCommitmentQty ?? ($isMonthlyCommitment || $campaign->is_monthly_recurring ? $quantity : null),
                'delivery_point' => $resolvedDeliveryPoint,
                'unit_price' => $unitPrice,
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'shipping_cost' => $shippingCost,
                'total_amount' => $totalAmount,
                'shipping_address' => $shippingAddress,
                'status' => 'pending_payment',
                'payment_deadline' => $paymentDeadline,
                'notes' => $notes,
            ];

            return $this->repository->createOrder($orderData);
        });
    }
}
