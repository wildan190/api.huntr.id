<?php

namespace App\Domain\Rfq\Actions;

use App\Domain\Auth\Models\User;
use App\Domain\Communication\Actions\BroadcastWebsocketNotificationAction;
use App\Domain\Communication\Notifications\DatabaseNotification;
use App\Domain\Rfq\Models\Rfq;
use App\Domain\Rfq\Models\RfqItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\UnauthorizedException;

class ResubmitRejectedRfqAction
{
    public function __construct(private readonly BroadcastWebsocketNotificationAction $broadcastAction) {}

    public function execute(User $requester, Rfq $rfq, array $data, ?string $documentPath = null): Rfq
    {
        $isOwner = (string) $rfq->company->owner_id === (string) $requester->id;
        $isRequester = (string) $rfq->user_id === (string) $requester->id;
        if (! $isOwner && ! $isRequester) {
            throw new UnauthorizedException('Only the PR requester or company owner can resubmit this PR.');
        }
        if ($rfq->status !== 'rejected') {
            abort(422, 'Only rejected PRs can be revised and resubmitted.');
        }

        return DB::transaction(function () use ($rfq, $requester, $data, $documentPath) {
            $rfq->items()->delete();

            $rfq->forceFill([
                'title' => $data['title'],
                'description' => $data['description'] ?? '',
                'duration_days' => $data['duration_days'] ?? 7,
                'delivery_point' => $data['delivery_point'] ?? null,
                'department' => $data['department'] ?? null,
                'warehouse_id' => $data['warehouse_id'] ?? null,
                'document_path' => $documentPath ?? $rfq->document_path,
                'status' => 'pending_approval',
                'approved_by' => null,
                'approved_at' => null,
                'rejected_by' => null,
                'rejected_at' => null,
                'rejection_reason' => null,
            ])->save();

            foreach ($data['items'] as $item) {
                RfqItem::create([
                    'rfq_id' => $rfq->id,
                    'catalogue_id' => $item['catalogue_id'],
                    'qty' => $item['qty'],
                    'estimated_price' => $item['estimated_price'] ?? null,
                    'expected_date' => $item['expected_date'],
                ]);
            }

            $rfq->company->approvers()->each(function (User $approver) use ($rfq) {
                $this->broadcastAction->execute('PR Resubmitted for Approval', "PR '{$rfq->title}' has been revised and submitted again.", 'test-channel', true, $approver->id, "/my-pr/{$rfq->id}", ['type' => 'pr_resubmitted', 'rfq_id' => $rfq->id]);
            });
            $rfq->company->notify(new DatabaseNotification('PR Resubmitted for Approval', "PR '{$rfq->title}' has been revised and submitted again.", "/my-pr/{$rfq->id}", null, ['type' => 'pr_resubmitted', 'rfq_id' => $rfq->id, 'resubmitted_by' => $requester->name]));

            return $rfq->fresh(['company', 'user', 'items.catalogue']);
        });
    }
}
