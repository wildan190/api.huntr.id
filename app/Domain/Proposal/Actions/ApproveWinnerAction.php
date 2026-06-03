<?php

namespace App\Domain\Proposal\Actions;

use App\Domain\Proposal\Models\Proposal;
use Illuminate\Validation\ValidationException;

class ApproveWinnerAction
{
    /**
     * Manager approves the awarded winner proposal.
     * This transitions the proposal from 'awarded' to 'approved' status.
     *
     * @param Proposal $proposal The awarded proposal to approve
     * @param int $managerUserId The manager user ID who approved
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

        // Update to approved
        $proposal->update([
            'winner_status' => 'approved',
            'approved_at' => now(),
            'approved_by_user_id' => $managerUserId,
        ]);

        return $proposal->fresh();
    }
}
