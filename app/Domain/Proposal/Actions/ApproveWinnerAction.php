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

            // Generate placeholder PDF for the Purchase Order
            $dummyPath = storage_path('app/public/invoices/dummy_proforma.pdf'); // Reuse same dummy for now
            $targetPath = storage_path("app/public/invoices/po_{$purchaseOrder->id}.pdf");
            if (file_exists($dummyPath)) {
                copy($dummyPath, $targetPath);
            }

            // 3. Notify the buyer (requester)
            $this->broadcastAction->execute(
                "Purchase Order Ready",
                "Your Purchase Order {$poNumber} has been generated and issued to vendor.",
                'test-channel',
                true,
                $rfq->user_id,
                "/orders?search={$poNumber}"
            );

            // 4. Notify the vendor
            $this->broadcastAction->execute(
                "Purchase Order Generated",
                "A new Purchase Order {$poNumber} has been generated for your proposal on '{$rfq->title}'.",
                'test-channel',
                true,
                null,
                "/orders?search={$poNumber}"
            );

            // Notify vendor users specifically
            foreach ($proposal->company->users as $vendorUser) {
                $this->broadcastAction->execute(
                    "PO Generated: {$poNumber}",
                    "PO has been generated for RFQ: {$rfq->title}. Please confirm the order.",
                    'test-channel',
                    true,
                    $vendorUser->id,
                    "/orders?search={$poNumber}"
                );
            }

            return $proposal->fresh();
        });
    }
}
