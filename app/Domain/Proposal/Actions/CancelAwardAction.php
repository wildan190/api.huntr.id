<?php

namespace App\Domain\Proposal\Actions;

use App\Domain\Proposal\Models\Proposal;
use App\Domain\Proposal\Models\ProposalItem;
use App\Domain\Rfq\Models\Rfq;
use App\Domain\Order\Models\PurchaseOrder;
use App\Domain\Auth\Models\User;
use App\Domain\Company\Services\CompanyAccessService;
use App\Domain\Communication\Actions\BroadcastWebsocketNotificationAction;
use App\Domain\Communication\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\Auth\Access\AuthorizationException;

class CancelAwardAction
{
    public function __construct(
        private readonly CompanyAccessService $companyAccess,
        private readonly BroadcastWebsocketNotificationAction $broadcastAction,
    ) {}

    /**
     * Final Approver or Purchasing Manager cancels an awarded tender.
     *
     * @param Rfq $rfq
     * @param string $managerUserId
     * @param string $reason
     * @return Rfq
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function execute(Rfq $rfq, string $managerUserId, string $reason): Rfq
    {
        $actor = User::query()->findOrFail($managerUserId);
        $buyerCompany = $rfq->company;

        // 1. Authorization: Only Manager, Approver, or Company Owner
        $isOwner = (string) $buyerCompany->owner_id === (string) $actor->id;
        $isManager = in_array($actor->role, ['manager', 'procurement_manager'], true);
        $canApprove = $this->companyAccess->can($actor, $buyerCompany, 'procurement.winner.approve');

        if (!$isOwner && !$isManager && !$canApprove) {
            throw new AuthorizationException('Hanya purchasing manager atau approver terakhir yang memiliki wewenang untuk membatalkan penetapan pemenang tender.');
        }

        // 2. Reason validation
        $reason = trim($reason);
        if (empty($reason) || strlen($reason) < 3) {
            throw ValidationException::withMessages([
                'reason' => ['Alasan pembatalan penetapan pemenang wajib diisi (minimal 3 karakter).'],
            ]);
        }

        // 3. Status verification
        $isAwarded = $rfq->status === 'awarded' || Proposal::where('rfq_id', $rfq->id)->whereIn('winner_status', ['awarded', 'approved'])->exists();
        if (!$isAwarded) {
            throw ValidationException::withMessages([
                'rfq' => ['Tender ini belum memiliki pemenang yang ditetapkan, sehingga tidak dapat dibatalkan.'],
            ]);
        }

        return DB::transaction(function () use ($rfq, $actor, $reason, $buyerCompany) {
            // A. Update RFQ status back to active and log cancellation audit
            $rfq->update([
                'status' => 'active',
                'award_mode' => 'single',
                'award_cancelled_at' => now(),
                'award_cancelled_by_user_id' => $actor->id,
                'award_cancellation_reason' => $reason,
            ]);

            // B. Reset all proposals for this RFQ
            $proposals = Proposal::where('rfq_id', $rfq->id)->get();
            $proposalIds = $proposals->pluck('id')->toArray();

            Proposal::whereIn('id', $proposalIds)->update([
                'winner_status' => 'none',
                'awarded_at' => null,
                'awarded_by_user_id' => null,
                'awarded_amount' => null,
            ]);

            // Reset proposal items is_awarded
            ProposalItem::whereIn('proposal_id', $proposalIds)->update([
                'is_awarded' => false,
            ]);

            // C. Cancel any generated Purchase Orders that are still in pending/issued state
            PurchaseOrder::where('rfq_id', $rfq->id)
                ->whereIn('status', ['pending_manager', 'issued', 'draft'])
                ->update([
                    'status' => 'cancelled',
                ]);

            DB::afterCommit(function () use ($rfq, $actor, $reason, $buyerCompany, $proposals) {
                // Notify Buyer Company users
                if ($buyerCompany) {
                    $buyerCompany->notify(new DatabaseNotification(
                        'Penetapan Pemenang Dibatalkan',
                        "Manager {$actor->name} telah membatalkan penetapan pemenang untuk tender '{$rfq->title}'. Alasan: {$reason}",
                        "/rfq/{$rfq->id}",
                        null,
                        ['type' => 'award_cancelled', 'rfq_id' => $rfq->id, 'reason' => $reason]
                    ));
                }

                // Notify Vendor Companies that were awarded
                foreach ($proposals as $prop) {
                    $vendorCompany = $prop->company;
                    if ($vendorCompany) {
                        $vendorCompany->notify(new DatabaseNotification(
                            'Status Pemenang Tender Dibatalkan',
                            "Penetapan pemenang pada tender '{$rfq->title}' telah dibatalkan oleh pihak manajemen pembeli. Alasan: {$reason}",
                            "/proposals",
                            null,
                            ['type' => 'award_cancelled_vendor', 'rfq_id' => $rfq->id, 'reason' => $reason]
                        ));
                    }
                }
            });

            Log::info("Award cancelled for RFQ {$rfq->id} by user {$actor->id}", [
                'reason' => $reason,
                'rfq_id' => $rfq->id,
            ]);

            return $rfq->fresh(['items', 'proposals.items', 'proposals.company']);
        });
    }
}

