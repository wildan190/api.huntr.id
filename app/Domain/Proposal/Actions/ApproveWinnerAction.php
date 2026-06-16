<?php

namespace App\Domain\Proposal\Actions;

use App\Domain\Proposal\Models\Proposal;
use App\Domain\Order\Repositories\OrderRepositoryInterface;
use App\Domain\Communication\Actions\BroadcastWebsocketNotificationAction;
use App\Domain\Communication\Notifications\DatabaseNotification;
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
        $rfq = $proposal->rfq;
        $poNumber = 'PO-' . date('Ymd') . '-' . strtoupper(substr($proposal->id, 0, 6));

        // Check if a PO for THIS proposal already exists (not just same rfq and vendor)
        $existingPoForThisProposal = \App\Domain\Order\Models\PurchaseOrder::where('proposal_id', $proposal->id)->first();
        
        // Also check for duplicate po_number just in case
        $existingPoByNumber = \App\Domain\Order\Models\PurchaseOrder::whereRaw('LOWER(po_number) = ?', [strtolower($poNumber)])->first();

        if ($existingPoForThisProposal) {
            // Only update existing PO if it's for THIS proposal
            $existingPoForThisProposal->update([
                'vendor_id'        => $proposal->company_id,
                'buyer_company_id' => $rfq->company_id,
                'total_amount'     => $proposal->price_offer,
                'purchase_type'    => $proposal->payment_term,
                'status'           => 'issued',
            ]);

            if ($proposal->winner_status !== 'approved') {
                $proposal->update([
                    'status' => 'accepted',
                    'winner_status' => 'approved',
                    'approved_at' => $proposal->approved_at ?? now(),
                    'approved_by_user_id' => $proposal->approved_by_user_id ?? $managerUserId,
                ]);
            }
            return $proposal;
        } elseif ($existingPoByNumber) {
            // If duplicate po_number, just increment a suffix
            $suffix = 2;
            while (\App\Domain\Order\Models\PurchaseOrder::whereRaw('LOWER(po_number) = ?', [strtolower($poNumber . '-' . $suffix)])->first()) {
                $suffix++;
            }
            $poNumber = $poNumber . '-' . $suffix;
        }

        // 2. Verify proposal is in 'awarded' state
        if ($proposal->winner_status !== 'awarded') {
            throw ValidationException::withMessages([
                'proposal' => ['This proposal has not been awarded yet or is already approved.'],
            ]);
        }

        return DB::transaction(function () use ($proposal, $managerUserId, $rfq, $poNumber) {
            // Re-check inside transaction with lock to prevent race conditions
            $existingPoInside = \App\Domain\Order\Models\PurchaseOrder::whereRaw('LOWER(po_number) = ?', [strtolower($poNumber)])
                ->lockForUpdate()
                ->first();

            if ($existingPoInside) {
                // Ensure correct vendor assignment and data even inside lock
                $existingPoInside->update([
                    'vendor_id'        => $proposal->company_id,
                    'buyer_company_id' => $rfq->company_id,
                    'total_amount'     => $proposal->price_offer,
                    'purchase_type'    => $proposal->payment_term,
                    'status'           => 'issued',
                ]);

                // Still need to update proposal if it's not approved
                if ($proposal->winner_status !== 'approved') {
                    $proposal->update([
                        'status' => 'accepted',
                        'winner_status' => 'approved',
                        'approved_at' => now(),
                        'approved_by_user_id' => $managerUserId,
                    ]);
                }

                return $proposal;
            }

            // 1. Update proposal to approved and accepted
            $proposal->update([
                'status' => 'accepted',
                'winner_status' => 'approved',
                'approved_at' => now(),
                'approved_by_user_id' => $managerUserId,
            ]);

            // 2. Generate Purchase Order
            $purchaseOrder = \App\Domain\Order\Models\PurchaseOrder::create([
                'buyer_company_id' => $rfq->company_id,
                'rfq_id'           => $rfq->id,
                'vendor_id'        => $proposal->company_id,
                'proposal_id'      => $proposal->id,
                'po_number'        => $poNumber,
                'status'           => 'issued',
                'order_date'       => now(),
                'created_by'       => $rfq->user_id,
                'approved_by'      => $managerUserId,
                'total_amount'     => $proposal->price_offer,
                'purchase_type'    => $proposal->payment_term,
            ]);

            // Generate placeholder PDF for the Purchase Order
            $dummyPath = storage_path('app/public/invoices/dummy_proforma.pdf'); // Reuse same dummy for now
            $targetPath = storage_path("app/public/invoices/po_{$purchaseOrder->id}.pdf");
            if (file_exists($dummyPath)) {
                copy($dummyPath, $targetPath);
            }

            DB::afterCommit(function () use ($rfq, $poNumber, $proposal) {
                $proposal->load(['company.users']);
                $vendorCompany = $proposal->company;
                $poUrl = "/orders?search={$poNumber}";
                $poData = ['type' => 'purchase_order_created', 'po_number' => $poNumber, 'rfq_id' => $rfq->id];

                // Notify the buyer (requester)
                $this->broadcastAction->execute(
                    'Purchase Order Ready',
                    "Your Purchase Order {$poNumber} has been generated and issued to vendor.",
                    'test-channel',
                    true,
                    $rfq->user_id,
                    $poUrl,
                    $poData
                );

                // Notify vendor company and its users
                if ($vendorCompany) {
                    $vendorCompany->notify(new DatabaseNotification(
                        'Purchase Order Generated',
                        "A new Purchase Order {$poNumber} has been generated for your proposal on '{$rfq->title}'.",
                        $poUrl,
                        null,
                        $poData
                    ));

                    foreach ($vendorCompany->users as $vendorUser) {
                        $this->broadcastAction->execute(
                            "PO Generated: {$poNumber}",
                            "PO has been generated for RFQ: {$rfq->title}. Please confirm the order.",
                            'test-channel',
                            true,
                            $vendorUser->id,
                            $poUrl,
                            $poData
                        );
                    }
                }
            });

            return $proposal->fresh();
        });
    }
}
