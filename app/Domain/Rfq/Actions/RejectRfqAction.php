<?php

namespace App\Domain\Rfq\Actions;

use App\Domain\Rfq\Models\Rfq;
use App\Domain\Auth\Models\User;
use App\Domain\Communication\Actions\BroadcastWebsocketNotificationAction;
use App\Domain\Communication\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;

/**
 * RejectRfqAction
 * 
 * Handles the rejection of RFQ/PR by authorized users (managers/approvers).
 * Updates status, logs rejection reason, and notifies relevant parties.
 */
class RejectRfqAction
{
    public function __construct(
        private BroadcastWebsocketNotificationAction $broadcastAction
    ) {}

    /**
     * Reject an RFQ/PR with proper validation and notifications.
     *
     * @param User $rejector The user rejecting the RFQ (must have approval rights)
     * @param Rfq $rfq The RFQ to reject
     * @param string|null $reason Optional rejection reason
     * @return Rfq
     * @throws \Exception
     */
    public function execute(User $rejector, Rfq $rfq, ?string $reason = null): Rfq
    {
        // Validate that the RFQ can be rejected
        if (!in_array($rfq->status, ['pending_approval', 'active'])) {
            throw new \Exception('RFQ can only be rejected when pending approval or active.');
        }

        // Validate that the user has permission to reject
        $isOwner = $rfq->company->owner_id === $rejector->id;
        
        if (!$rejector->hasRole('manager') && !$isOwner) {
            throw new \Exception('Only purchasing managers or company owners can reject RFQs.');
        }

        return DB::transaction(function () use ($rfq, $rejector, $reason) {
            // Store original status before updating
            $originalStatus = $rfq->status;
            
            // Update RFQ status and rejection details
            $rfq->update([
                'status' => 'rejected',
                'rejected_at' => now(),
                'rejected_by' => $rejector->id,
                'rejection_reason' => $reason,
            ]);

            // Refresh the model to get updated data
            $rfq->refresh();

            // Notify the requester
            if ($rfq->user_id && $rfq->user_id !== $rejector->id) {
                $this->broadcastAction->execute(
                    "PR Rejected",
                    "Your PR '{$rfq->title}' has been rejected" . ($reason ? ": {$reason}" : '.'),
                    'test-channel',
                    true,
                    $rfq->user_id,
                    "/my-pr/{$rfq->id}",
                    ['type' => 'pr_rejected', 'rfq_id' => $rfq->id]
                );
            }

            // Notify company admins/managers
            $company = $rfq->company;
            $notificationData = [
                'type' => 'pr_rejected',
                'rfq_id' => $rfq->id,
                'rejected_by' => $rejector->name,
                'reason' => $reason
            ];

            $company->notify(new DatabaseNotification(
                'PR Rejected',
                "PR '{$rfq->title}' has been rejected by {$rejector->name}" . ($reason ? ": {$reason}" : '.'),
                "/my-pr/{$rfq->id}",
                null,
                $notificationData
            ));

            // If RFQ was active (published to vendors), notify them as well
            if ($originalStatus === 'active' && $rfq->proposals()->exists()) {
                $vendorCompanies = $rfq->proposals()->with('company')->get()->pluck('company')->unique('id');
                
                foreach ($vendorCompanies as $vendorCompany) {
                    $vendorCompany->notify(new DatabaseNotification(
                        'RFQ Cancelled',
                        "RFQ '{$rfq->title}' has been cancelled by the buyer.",
                        "/all-requests",
                        null,
                        ['type' => 'rfq_cancelled', 'rfq_id' => $rfq->id]
                    ));
                }
            }

            return $rfq;
        });
    }
}