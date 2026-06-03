<?php

namespace App\Domain\Proposal\Actions;

use App\Domain\Proposal\Models\Proposal;
use App\Domain\Order\Repositories\OrderRepositoryInterface;
use App\Domain\Communication\Actions\BroadcastWebsocketNotificationAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApproveWinnerAction
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly BroadcastWebsocketNotificationAction $broadcastAction
    ) {}

    /**
     * Manager approves the awarded winner proposal.
     * This transitions the proposal from 'awarded' to 'approved' status
     * and automatically generates a Purchase Order (PO).
     *
     * @param Proposal $proposal The awarded proposal to approve
     * @param string $managerUserId The manager user ID who approved
     * @return Proposal
     * @throws ValidationException
     */
    public function execute(Proposal $proposal, string $managerUserId): Proposal
    {
        // Verify proposal is in 'awarded' state
        if ($proposal->winner_status !== 'awarded') {
            throw ValidationException::withMessages([
                'proposal' => ['This proposal has not been awarded yet or is already approved.'],
            ]);
        }

        return DB::transaction(function () use ($proposal, $managerUserId) {
            // 1. Update proposal to approved and accepted
            $proposal->update([
                'status' => 'accepted',
                'winner_status' => 'approved',
                'approved_at' => now(),
                'approved_by_user_id' => $managerUserId,
            ]);

            // 2. Generate Purchase Order
            $rfq = $proposal->rfq;
            $poNumber = 'PO-' . date('Ymd') . '-' . strtoupper(substr($proposal->id, 0, 6));

            $purchaseOrder = $this->orderRepository->createPurchaseOrder([
                'buyer_company_id' => $rfq->company_id,
                'rfq_id'           => $rfq->id,
                'vendor_id'        => $proposal->company_id,
                'po_number'        => $poNumber,
                'status'           => 'published', // Automatically published to vendor
                'order_date'       => now(),
                'created_by'       => $rfq->user_id,
                'approved_by'      => $managerUserId,
            ]);

            // 3. Notify the vendor
            $this->broadcastAction->execute(
                "Purchase Order Generated",
                "A new Purchase Order {$poNumber} has been generated for your proposal on '{$rfq->title}'.",
                'test-channel',
                true,
                null,
                "/orders"
            );

            // Notify vendor users specifically
            foreach ($proposal->company->users as $vendorUser) {
                $this->broadcastAction->execute(
                    "PO Generated: {$poNumber}",
                    "PO has been generated for RFQ: {$rfq->title}. Please confirm the order.",
                    'test-channel',
                    true,
                    $vendorUser->id,
                    "/orders"
                );
            }

            return $proposal->fresh();
        });
    }
}
