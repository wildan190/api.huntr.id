<?php

namespace App\Domain\CrowdBuy\Actions;

use App\Domain\CrowdBuy\Models\CrowdBuyCampaign;
use App\Domain\CrowdBuy\Models\CrowdBuyOrder;
use App\Domain\CrowdBuy\Models\CrowdBuyPayment;
use App\Domain\Payment\Services\MidtransService;
use App\Domain\Communication\Actions\BroadcastWebsocketNotificationAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ProcessCrowdBuyPaymentAction
{
    public function __construct(
        protected MidtransService $midtrans,
        protected BroadcastWebsocketNotificationAction $broadcast
    ) {}

    /**
     * Initiate payment transaction for order.
     */
    public function initiatePayment(CrowdBuyOrder $order, string $paymentMethod = 'bank_transfer'): CrowdBuyPayment
    {
        if ($order->status !== 'pending_payment') {
            throw new \DomainException('Order ini tidak dapat dibayar (status saat ini: ' . $order->status . ').');
        }

        $externalId = "CB-PAY-{$order->order_number}-" . Str::random(4);

        // Attempt Midtrans charge or fallback to dummy/token
        $paymentInfo = [];
        $rawResponse = [];

        try {
            $payload = [
                'payment_type' => 'bank_transfer',
                'transaction_details' => [
                    'order_id' => $externalId,
                    'gross_amount' => (int) $order->total_amount,
                ],
                'bank_transfer' => [
                    'bank' => in_array($paymentMethod, ['bca', 'bni', 'bri', 'mandiri']) ? $paymentMethod : 'bca',
                ],
            ];

            $response = $this->midtrans->charge($payload);
            $rawResponse = $response;
            $paymentInfo = [
                'va_number' => $response['va_numbers'][0]['va_number'] ?? null,
                'bank' => $response['va_numbers'][0]['bank'] ?? $paymentMethod,
                'payment_url' => $response['redirect_url'] ?? null,
            ];
        } catch (\Throwable $e) {
            Log::warning('CrowdBuy Midtrans Charge skipped or failed, using simulation payment', [
                'error' => $e->getMessage(),
                'order_id' => $order->id,
            ]);

            // Simulation payload for development/sandbox
            $paymentInfo = [
                'va_number' => '988' . rand(100000000, 999999999),
                'bank' => $paymentMethod,
                'simulation' => true,
            ];
            $rawResponse = ['status_message' => 'Simulated payment created', 'error' => $e->getMessage()];
        }

        return CrowdBuyPayment::create([
            'crowd_buy_order_id' => $order->id,
            'external_id' => $externalId,
            'amount' => $order->total_amount,
            'payment_type' => 'bank_transfer',
            'payment_method' => $paymentMethod,
            'status' => 'pending',
            'payment_info' => $paymentInfo,
            'raw_response' => $rawResponse,
        ]);
    }

    /**
     * Idempotent payment settlement handler (called by webhook or simulation).
     */
    public function markAsPaid(CrowdBuyOrder $order, string $transactionId, array $payload = []): CrowdBuyOrder
    {
        return DB::transaction(function () use ($order, $transactionId, $payload) {
            // Re-fetch order and campaign with lock
            $lockedOrder = CrowdBuyOrder::where('id', $order->id)->lockForUpdate()->firstOrFail();

            // IDEMPOTENCY CHECK: If already paid or beyond, return immediately
            if (in_array($lockedOrder->status, ['paid', 'processing', 'shipped', 'delivered'])) {
                Log::info('CrowdBuy payment already processed, skipping duplicate event', [
                    'order_id' => $lockedOrder->id,
                    'current_status' => $lockedOrder->status,
                ]);
                return $lockedOrder;
            }

            $lockedCampaign = CrowdBuyCampaign::where('id', $lockedOrder->campaign_id)->lockForUpdate()->firstOrFail();

            // 1. Update Order Status
            $lockedOrder->update([
                'status' => 'paid',
                'paid_at' => now(),
            ]);

            // 2. Update Payment record
            CrowdBuyPayment::where('crowd_buy_order_id', $lockedOrder->id)
                ->where('status', 'pending')
                ->latest()
                ->first()
                ?->update([
                    'status' => 'paid',
                    'transaction_id' => $transactionId,
                    'paid_at' => now(),
                    'raw_response' => array_merge($payload),
                ]);

            // 3. Atomically increment aggregated quantity on campaign
            $newQuantity = $lockedCampaign->current_quantity + $lockedOrder->quantity;
            $lockedCampaign->update([
                'current_quantity' => $newQuantity,
            ]);

            Log::info('CrowdBuy Order Paid successfully', [
                'order_number' => $lockedOrder->order_number,
                'added_quantity' => $lockedOrder->quantity,
                'total_campaign_quantity' => $newQuantity,
                'target_quantity' => $lockedCampaign->target_quantity,
            ]);

            // 4. Notifications
            try {
                // Notify Buyer
                $this->broadcast->execute(
                    'Pembayaran Crowd Buy Berhasil',
                    "Pembayaran untuk order {$lockedOrder->order_number} sebesar Rp " . number_format($lockedOrder->total_amount, 0, ',', '.') . " telah diterima.",
                    'test-channel',
                    true,
                    $lockedOrder->user_id,
                    "/crowd-buy"
                );

                // Notify Supplier
                $this->broadcast->execute(
                    'Order Crowd Buy Terbayar',
                    "Order baru {$lockedOrder->order_number} ({$lockedOrder->quantity} {$lockedCampaign->unit}) telah dibayar pada campaign {$lockedCampaign->title}.",
                    'test-channel',
                    true,
                    $lockedCampaign->created_by,
                    "/crowd-buy"
                );

                // Check if target reached for the first time
                if ($newQuantity >= $lockedCampaign->target_quantity && ($newQuantity - $lockedOrder->quantity) < $lockedCampaign->target_quantity) {
                    $this->broadcast->execute(
                        'Target Crowd Buy Tercapai! 🎉',
                        "Campaign '{$lockedCampaign->title}' telah mencapai 100% target kuantitas ({$newQuantity}/{$lockedCampaign->target_quantity} {$lockedCampaign->unit}).",
                        'test-channel',
                        false,
                        null,
                        "/crowd-buy"
                    );
                }
            } catch (\Throwable $e) {
                // Ignore broadcast exception
            }

            return $lockedOrder->fresh();
        });
    }
}
