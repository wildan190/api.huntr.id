<?php

namespace App\Domain\Rfq\Actions;

use App\Domain\Auth\Models\User;
use App\Domain\Communication\Actions\BroadcastWebsocketNotificationAction;
use App\Domain\Communication\Notifications\DatabaseNotification;
use App\Domain\Rfq\Models\Rfq;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\UnauthorizedException;

class ResubmitRejectedRfqAction
{
    public function __construct(private readonly BroadcastWebsocketNotificationAction $broadcastAction) {}

    public function execute(User $requester, Rfq $rfq): Rfq
    {
        $isOwner = (string) $rfq->company->owner_id === (string) $requester->id;
        $isRequester = (string) $rfq->user_id === (string) $requester->id;
        if (! $isOwner && ! $isRequester) {
            throw new UnauthorizedException('Only the PR requester or company owner can resubmit this PR.');
        }
        if ($rfq->status !== 'rejected') {
            abort(422, 'Only rejected PRs can be revised and resubmitted.');
        }

        return DB::transaction(function () use ($rfq, $requester) {
            $rfq->forceFill([
                'status' => 'pending_approval',
                'approved_by' => null,
                'approved_at' => null,
                'rejected_by' => null,
                'rejected_at' => null,
                'rejection_reason' => null,
            ])->save();

            $rfq->company->approvers()->each(function (User $approver) use ($rfq) {
                $this->broadcastAction->execute('PR Resubmitted for Approval', "PR '{$rfq->title}' has been revised and submitted again.", 'test-channel', true, $approver->id, "/my-pr/{$rfq->id}", ['type' => 'pr_resubmitted', 'rfq_id' => $rfq->id]);
            });
            $rfq->company->notify(new DatabaseNotification('PR Resubmitted for Approval', "PR '{$rfq->title}' has been revised and submitted again.", "/my-pr/{$rfq->id}", null, ['type' => 'pr_resubmitted', 'rfq_id' => $rfq->id, 'resubmitted_by' => $requester->name]));

            return $rfq->fresh(['company', 'user', 'items.catalogue']);
        });
    }
}
