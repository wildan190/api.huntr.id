<?php

namespace App\Domain\CrowdBuy\Actions;

use App\Domain\Auth\Models\User;
use App\Domain\CrowdBuy\Models\CrowdBuyOrder;
use App\Domain\CrowdBuy\Repositories\CrowdBuyRepositoryInterface;
use App\Domain\Communication\Actions\BroadcastWebsocketNotificationAction;

class FulfillCrowdBuyOrderAction
{
    public function __construct(
        protected CrowdBuyRepositoryInterface $repository,
        protected BroadcastWebsocketNotificationAction $broadcast
    ) {}

    public function execute(CrowdBuyOrder $order, User $supplierUser, array $fulfillmentData): CrowdBuyOrder
    {
        // Check supplier ownership
        $campaign = $order->campaign;
        if ($campaign->supplier_company_id !== $supplierUser->company_id && !$supplierUser->hasRole('admin')) {
            throw new \DomainException('Anda tidak berwenang mengelola pemenuhan pesanan ini.');
        }

        $newStatus = $fulfillmentData['status'] ?? null;
        $updates = [];

        if ($newStatus === 'shipped') {
            if (empty($fulfillmentData['carrier']) || empty($fulfillmentData['tracking_number'])) {
                throw new \InvalidArgumentException('Kurir (carrier) dan nomor resi (tracking number) wajib diisi untuk status shipped.');
            }
            $updates = [
                'status' => 'shipped',
                'carrier' => $fulfillmentData['carrier'],
                'tracking_number' => $fulfillmentData['tracking_number'],
                'shipped_at' => now(),
            ];
        } elseif ($newStatus === 'delivered') {
            $updates = [
                'status' => 'delivered',
                'delivered_at' => now(),
            ];
        } elseif ($newStatus === 'processing') {
            $updates = [
                'status' => 'processing',
            ];
        }

        if (!empty($fulfillmentData['notes'])) {
            $updates['notes'] = $fulfillmentData['notes'];
        }

        $updatedOrder = $this->repository->updateOrder($order, $updates);

        // Notify Buyer
        try {
            $statusText = match ($newStatus) {
                'shipped' => "Pesanan Anda {$order->order_number} telah dikirim via {$updatedOrder->carrier} (Resi: {$updatedOrder->tracking_number}).",
                'delivered' => "Pesanan Anda {$order->order_number} telah berhasil dikirimkan/diterima.",
                'processing' => "Pesanan Anda {$order->order_number} sedang dipersiapkan oleh supplier.",
                default => "Status pesanan {$order->order_number} telah diperbarui.",
            };

            $this->broadcast->execute(
                "Update Pengiriman Pesanan Crowd Buy",
                $statusText,
                'test-channel',
                true,
                $order->user_id,
                "/crowd-buy"
            );
        } catch (\Throwable $e) {}

        return $updatedOrder;
    }
}
