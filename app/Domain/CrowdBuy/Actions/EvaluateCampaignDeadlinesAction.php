<?php

namespace App\Domain\CrowdBuy\Actions;

use App\Domain\CrowdBuy\Models\CrowdBuyCampaign;
use App\Domain\CrowdBuy\Models\CrowdBuyOrder;
use App\Domain\CrowdBuy\Repositories\CrowdBuyRepositoryInterface;
use App\Domain\Communication\Actions\BroadcastWebsocketNotificationAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EvaluateCampaignDeadlinesAction
{
    public function __construct(
        protected CrowdBuyRepositoryInterface $repository,
        protected BroadcastWebsocketNotificationAction $broadcast
    ) {}

    public function execute(): array
    {
        // Find campaigns that are active but deadline has passed
        $campaigns = CrowdBuyCampaign::where('status', 'active')
            ->where('ends_at', '<=', now())
            ->get();

        $evaluated = [
            'successful' => 0,
            'failed' => 0,
        ];

        foreach ($campaigns as $campaign) {
            DB::transaction(function () use ($campaign, &$evaluated) {
                // Re-fetch with lock
                /** @var CrowdBuyCampaign $lockedCampaign */
                $lockedCampaign = CrowdBuyCampaign::where('id', $campaign->id)->lockForUpdate()->first();
                if (!$lockedCampaign || $lockedCampaign->status !== 'active') {
                    return;
                }

                $isSuccessful = $lockedCampaign->current_quantity >= $lockedCampaign->target_quantity;
                $newStatus = $isSuccessful ? 'successful' : 'failed';

                $lockedCampaign->update([
                    'status' => $newStatus,
                ]);

                $this->repository->recordHistory(
                    $lockedCampaign,
                    null,
                    'active',
                    $newStatus,
                    $isSuccessful
                        ? "Campaign deadline reached. Target fulfilled ({$lockedCampaign->current_quantity}/{$lockedCampaign->target_quantity} {$lockedCampaign->unit}). Proceeding to fulfillment."
                        : "Campaign deadline reached. Target NOT fulfilled ({$lockedCampaign->current_quantity}/{$lockedCampaign->target_quantity} {$lockedCampaign->unit}). Orders scheduled for refund."
                );

                if ($isSuccessful) {
                    $evaluated['successful']++;

                    // Move paid orders to processing
                    CrowdBuyOrder::where('campaign_id', $lockedCampaign->id)
                        ->where('status', 'paid')
                        ->update(['status' => 'processing']);

                    // Cancel pending unpaid orders
                    CrowdBuyOrder::where('campaign_id', $lockedCampaign->id)
                        ->where('status', 'pending_payment')
                        ->update([
                            'status' => 'cancelled',
                            'cancellation_reason' => 'Campaign ended before payment completed',
                        ]);

                    // Notify Supplier
                    try {
                        $this->broadcast->execute(
                            'Campaign Crowd Buy Berhasil! 🎉',
                            "Campaign {$lockedCampaign->title} telah sukses mencapai target ({$lockedCampaign->current_quantity} {$lockedCampaign->unit}). Silakan persiapkan pengiriman pesanan.",
                            'test-channel',
                            true,
                            $lockedCampaign->created_by,
                            "/crowd-buy"
                        );
                    } catch (\Throwable $e) {}
                } else {
                    $evaluated['failed']++;

                    // Mark paid orders as refund_pending
                    CrowdBuyOrder::where('campaign_id', $lockedCampaign->id)
                        ->where('status', 'paid')
                        ->update([
                            'status' => 'refund_pending',
                            'cancellation_reason' => 'Campaign gagal mencapai target minimum.',
                        ]);

                    // Cancel pending unpaid orders
                    CrowdBuyOrder::where('campaign_id', $lockedCampaign->id)
                        ->where('status', 'pending_payment')
                        ->update([
                            'status' => 'cancelled',
                            'cancellation_reason' => 'Campaign gagal mencapai target minimum.',
                        ]);

                    // Notify Supplier
                    try {
                        $this->broadcast->execute(
                            'Campaign Crowd Buy Gagal',
                            "Campaign {$lockedCampaign->title} ditutup karena tidak mencapai target minimum hingga batas waktu.",
                            'test-channel',
                            true,
                            $lockedCampaign->created_by,
                            "/crowd-buy"
                        );
                    } catch (\Throwable $e) {}
                }

                Log::info("Evaluated Crowd Buy campaign outcome", [
                    'campaign_id' => $lockedCampaign->id,
                    'code' => $lockedCampaign->code,
                    'result' => $newStatus,
                    'quantity' => $lockedCampaign->current_quantity,
                    'target' => $lockedCampaign->target_quantity,
                ]);
            });
        }

        return $evaluated;
    }
}
