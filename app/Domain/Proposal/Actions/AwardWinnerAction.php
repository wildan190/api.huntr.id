<?php

namespace App\Domain\Proposal\Actions;

use App\Domain\Proposal\Models\Proposal;
use App\Domain\Rfq\Models\Rfq;
use App\Domain\Company\Models\Company;
use App\Domain\Communication\Actions\BroadcastWebsocketNotificationAction;
use App\Domain\Communication\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AwardWinnerAction
{
    public function __construct(
        private readonly BroadcastWebsocketNotificationAction $broadcastAction
    ) {}

    /**
     * Buyer awards a proposal as the winner for an RFQ.
     * This transition proposal to 'awarded' status, marking it as selected by buyer.
     * Other proposals for the same RFQ will be marked as 'rejected'.
     *
     * @param Proposal $proposal The proposal to award
     * @param int $buyerUserId The buyer user ID who made the decision
     * @param Rfq $rfq The target RFQ
     * @return Proposal
     * @throws ValidationException
     */
    public function execute(Proposal $proposal, string $buyerUserId, Rfq $rfq): Proposal
    {
    /**
     * Verify proposal belongs to this RFQ
     */
    if ($proposal->rfq_id !== $rfq->id) {
        throw ValidationException::withMessages([
            'proposal' => ['This proposal does not belong to the selected RFQ.'],
        ]);
    }

        // Idempotency check: If already awarded, return success silently
        if ($proposal->winner_status === 'awarded') {
            return $proposal->fresh();
        }

        // Verify RFQ is not already closed
        if ($rfq->status !== 'active') {
            throw ValidationException::withMessages([
                'rfq' => ['Cannot award winner for inactive RFQ.'],
            ]);
        }

        return DB::transaction(function () use ($proposal, $buyerUserId, $rfq) {
            // 1. Mark all other proposals for this RFQ as rejected
            Proposal::where('rfq_id', $rfq->id)
                ->where('id', '!=', $proposal->id)
                ->update(['winner_status' => 'rejected']);

            // 2. Mark the selected proposal as awarded
            $proposal->update([
                'winner_status' => 'awarded',
                'awarded_at' => now(),
                'awarded_by_user_id' => $buyerUserId,
            ]);

            // 3. Update RFQ status to 'awarded'
            $rfq->update(['status' => 'awarded']);

            DB::afterCommit(function () use ($proposal, $rfq) {
                $proposal->load(['company.users']);
                $rfq->load('company');
                $vendorCompany = $proposal->company;
                $buyerCompany = $rfq->company;

                // Notify the winning vendor company and its users
                if ($vendorCompany) {
                    $vendorCompany->notify(new DatabaseNotification(
                        'Proposal Selected as Winner!',
                        "Congratulations! Your proposal for RFQ '{$rfq->title}' has been selected as the winner!",
                        '/proposals',
                        null,
                        ['type' => 'proposal_awarded', 'rfq_id' => $rfq->id, 'proposal_id' => $proposal->id]
                    ));

                    foreach ($vendorCompany->users as $vendorUser) {
                        $this->broadcastAction->execute(
                            'Proposal Selected as Winner!',
                            "Congratulations! Your proposal for RFQ '{$rfq->title}' has been selected as the winner!",
                            'test-channel',
                            true,
                            $vendorUser->id,
                            '/proposals',
                            ['type' => 'proposal_awarded', 'rfq_id' => $rfq->id, 'proposal_id' => $proposal->id]
                        );
                    }
                }

                // Notify buyer managers (and owner) for PO approval
                if ($buyerCompany) {
                    $buyerCompany->notify(new DatabaseNotification(
                        'Winner Requires Approval',
                        "A winner has been awarded for RFQ '{$rfq->title}' and requires your approval.",
                        '/approvals',
                        null,
                        ['type' => 'winner_approval', 'rfq_id' => $rfq->id, 'proposal_id' => $proposal->id]
                    ));

                    foreach ($buyerCompany->approvers() as $manager) {
                        $this->broadcastAction->execute(
                            'Winner Requires Approval',
                            "A winner has been awarded for RFQ '{$rfq->title}' and requires your approval.",
                            'test-channel',
                            true,
                            $manager->id,
                            '/approvals',
                            ['type' => 'winner_approval', 'rfq_id' => $rfq->id, 'proposal_id' => $proposal->id]
                        );
                    }
                }
            });

            return $proposal->fresh();
        });
    }
}
