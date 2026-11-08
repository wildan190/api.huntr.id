<?php

namespace App\Domain\Proposal\Actions;

use App\Domain\Proposal\Models\Proposal;
use App\Domain\Proposal\Models\ProposalItem;
use App\Domain\Rfq\Models\Rfq;
use App\Domain\Communication\Actions\BroadcastWebsocketNotificationAction;
use App\Domain\Communication\Actions\SendWhatsAppNotificationAction;
use App\Domain\Communication\Notifications\DatabaseNotification;
use App\Domain\AI\Services\DemoBotService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AwardMultiWinnersAction
{
    public function __construct(
        private readonly BroadcastWebsocketNotificationAction $broadcastAction,
        private readonly SendWhatsAppNotificationAction $whatsAppAction,
        private readonly ApproveWinnerAction $approveWinnerAction,
        private readonly DemoBotService $demoBotService
    ) {}

    /**
     * Buyer awards vendor proposals as winners (separate order / split award / multi-vendor).
     *
     * Requirements:
     * - Vendor count must be >= 1 and <= 10.
     * - Only checked items per vendor will be awarded and issued to PO.
     *
     * @param Rfq $rfq
     * @param string $buyerUserId
     * @param array<int, array{proposal_id: string, item_ids: array<int, string>}> $awards
     * @param string $awardMode
     * @param bool $generatePo
     * @return array<int, Proposal>
     * @throws ValidationException
     */
    public function execute(
        Rfq $rfq,
        string $buyerUserId,
        array $awards,
        string $awardMode = 'separate_order',
        bool $generatePo = true
    ): array
    {
        // 1. Verify RFQ status
        if (!in_array($rfq->status, ['active', 'open', 'approved'], true)) {
            throw ValidationException::withMessages([
                'rfq' => ['Cannot award winners for inactive RFQ.'],
            ]);
        }

        // 2. Validate vendor count
        $vendorCount = count($awards);
        if ($vendorCount < 1) {
            throw ValidationException::withMessages([
                'awards' => ['Minimal 1 vendor harus dipilih untuk pembuatan Purchase Order.'],
            ]);
        }
        if ($vendorCount > 10) {
            throw ValidationException::withMessages([
                'awards' => ['Maksimal pemenang tender adalah 10 vendor.'],
            ]);
        }

        // 3. Validate each proposal and items
        $awardedProposalIds = [];
        $rfqItems = $rfq->items()->get()->keyBy('id');

        foreach ($awards as $idx => $award) {
            $proposalId = $award['proposal_id'] ?? null;
            $itemIds = $award['item_ids'] ?? [];

            if (!$proposalId) {
                throw ValidationException::withMessages([
                    "awards.{$idx}" => ['Proposal ID wajib diisi.'],
                ]);
            }

            if (empty($itemIds) || !is_array($itemIds)) {
                throw ValidationException::withMessages([
                    "awards.{$idx}" => ['Setiap vendor pemenang harus memiliki minimal 1 item yang di-checklist.'],
                ]);
            }

            $proposal = Proposal::where('rfq_id', $rfq->id)->find($proposalId);
            if (!$proposal) {
                throw ValidationException::withMessages([
                    "awards.{$idx}" => ["Proposal {$proposalId} tidak valid atau tidak terdaftar pada RFQ ini."],
                ]);
            }

            $awardedProposalIds[] = $proposalId;
        }

        // Check for duplicate proposal in awards
        if (count($awardedProposalIds) !== count(array_unique($awardedProposalIds))) {
            throw ValidationException::withMessages([
                'awards' => ['Setiap vendor hanya boleh didaftarkan 1 kali dalam daftar pemenang.'],
            ]);
        }

        $awardedProposals = [];

        DB::transaction(function () use ($rfq, $buyerUserId, $awards, $awardedProposalIds, $awardMode, $rfqItems, $generatePo, &$awardedProposals) {
            // 1. Reject all other proposals for this RFQ
            Proposal::where('rfq_id', $rfq->id)
                ->whereNotIn('id', $awardedProposalIds)
                ->update([
                    'winner_status' => 'rejected',
                    'status' => 'rejected',
                ]);

            // 2. Award each selected proposal with only its checked items
            foreach ($awards as $award) {
                $proposalId = $award['proposal_id'];
                $checkedItemIds = $award['item_ids']; // array of rfq_item_id or proposal_item_id

                $proposal = Proposal::where('rfq_id', $rfq->id)->findOrFail($proposalId);

                // Reset is_awarded for all items belonging to this proposal
                ProposalItem::where('proposal_id', $proposal->id)->update(['is_awarded' => false]);

                // Set is_awarded = true for checked items
                ProposalItem::where('proposal_id', $proposal->id)
                    ->where(function ($q) use ($checkedItemIds) {
                        $q->whereIn('rfq_item_id', $checkedItemIds)
                            ->orWhereIn('id', $checkedItemIds);
                    })
                    ->update(['is_awarded' => true]);

                // Calculate awarded amount based only on checked items
                $awardedProposalItems = ProposalItem::where('proposal_id', $proposal->id)
                    ->where('is_awarded', true)
                    ->get();

                $awardedTotal = 0;
                foreach ($awardedProposalItems as $pItem) {
                    $rfqItem = $rfqItems->get($pItem->rfq_item_id);
                    $qty = $rfqItem?->qty ?? 1;
                    $awardedTotal += ($pItem->price_offer * $qty);
                }

                $proposal->update([
                    'winner_status' => 'awarded',
                    'awarded_at' => now(),
                    'awarded_by_user_id' => $buyerUserId,
                    'awarded_amount' => $awardedTotal,
                ]);

                $awardedProposals[] = $proposal->fresh(['company', 'items.rfqItem.catalogue']);
            }

            // 3. Mark RFQ as awarded with the given award mode
            $rfq->update([
                'status' => 'awarded',
                'award_mode' => $awardMode,
            ]);

            // 4. Generate Purchase Orders for each awarded proposal if generatePo is true
            if ($generatePo || config('app.demo_mode', false)) {
                foreach ($awardedProposals as $awardedProposal) {
                    try {
                        $approvedProposal = $this->approveWinnerAction->execute($awardedProposal->fresh(), $buyerUserId);
                        if (config('app.demo_mode', false) && !empty($approvedProposal->generatedPurchaseOrder)) {
                            $this->demoBotService->handleBotConfirmPo($approvedProposal->generatedPurchaseOrder);
                        }
                    } catch (\Exception $e) {
                        Log::warning("Generate PO for separate order failed: " . $e->getMessage());
                    }
                }
            }

            DB::afterCommit(function () use ($awardedProposals, $rfq) {
                $rfq->load('company');
                foreach ($awardedProposals as $proposal) {
                    $proposal->load(['company.users']);
                    $vendorCompany = $proposal->company;

                    if ($vendorCompany) {
                        $vendorCompany->notify(new DatabaseNotification(
                            'Proposal Selected as Winner!',
                            "Selamat! Proposal Anda untuk RFQ '{$rfq->title}' telah terpilih sebagai salah satu pemenang tender!",
                            '/proposals',
                            null,
                            ['type' => 'proposal_awarded', 'rfq_id' => $rfq->id, 'proposal_id' => $proposal->id]
                        ));

                        foreach ($vendorCompany->users as $vendorUser) {
                            $this->broadcastAction->execute(
                                'Proposal Selected as Winner!',
                                "Selamat! Proposal Anda untuk RFQ '{$rfq->title}' terpilih sebagai pemenang tender!",
                                'test-channel',
                                true,
                                $vendorUser->id,
                                '/proposals',
                                ['type' => 'proposal_awarded', 'rfq_id' => $rfq->id, 'proposal_id' => $proposal->id]
                            );
                        }

                        $this->whatsAppAction->toCompany(
                            $vendorCompany,
                            "Selamat! Proposal Anda untuk RFQ '{$rfq->title}' terpilih sebagai pemenang tender. PO akan segera diterbitkan untuk item-item yang dimenangkan.",
                            false
                        );
                    }
                }
            });
        });

        return $awardedProposals;
    }
}
