<?php

namespace App\Domain\Proposal\Actions;

use App\Domain\Proposal\Repositories\ProposalRepositoryInterface;
use App\Domain\Company\Models\Company;
use App\Domain\Proposal\Models\Proposal;
use App\Domain\Proposal\Models\ProposalItem;
use App\Domain\Rfq\Models\Rfq;
use App\Domain\Communication\Notifications\DatabaseNotification;
use App\Domain\Communication\Actions\BroadcastWebsocketNotificationAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class SubmitProposalAction
{
    public function __construct(
        private readonly ProposalRepositoryInterface $proposalRepository,
        private readonly BroadcastWebsocketNotificationAction $broadcastAction
    ) {}

    /**
     * Vendor submits a proposal offer to an active RFQ.
     *
     * @param Company $vendorCompany The vendor's company
     * @param Rfq $rfq The target active RFQ
     * @param array $data Input fields: items (array of rfq_item_id => price_offer), delivery_days, warranty_months
     * @return Proposal
     * @throws ValidationException
     */
    public function execute(Company $vendorCompany, Rfq $rfq, array $data): Proposal
    {
        if ($rfq->status !== 'active') {
            throw ValidationException::withMessages([
                'rfq' => ['You can only submit proposals to active RFQs.'],
            ]);
        }

        // Check if RFQ tender deadline has expired
        if ($this->isTenderExpired($rfq)) {
            throw ValidationException::withMessages([
                'rfq' => ['The tender period for this RFQ has expired. No more proposals can be submitted.'],
            ]);
        }

        if ($this->proposalRepository->hasSubmittedForRfq($rfq, $vendorCompany)) {
            throw ValidationException::withMessages([
                'rfq' => ['Your company has already submitted a proposal for this RFQ.'],
            ]);
        }

        return DB::transaction(function () use ($vendorCompany, $rfq, $data) {
            $totalPriceOffer = 0;
            $items = $data['items'] ?? [];

            foreach ($items as $item) {
                $totalPriceOffer += ($item['price_offer'] * ($rfq->items()->find($item['rfq_item_id'])->qty ?? 0));
            }

            // Fallback to single price_offer if items are not provided (legacy support)
            if (empty($items) && isset($data['price_offer'])) {
                $totalPriceOffer = $data['price_offer'];
            }

            $proposal = $this->proposalRepository->create([
                'rfq_id'          => $rfq->id,
                'company_id'      => $vendorCompany->id,
                'price_offer'     => $totalPriceOffer,
                'delivery_days'   => $data['delivery_days'],
                'warranty_months' => $data['warranty_months'] ?? 12,
                'document_path'   => $data['document_path'] ?? null,
                'payment_term'    => $data['payment_term'] ?? null,
                'status'          => 'submitted',
            ]);

            foreach ($items as $item) {
                ProposalItem::create([
                    'proposal_id' => $proposal->id,
                    'rfq_item_id' => $item['rfq_item_id'],
                    'price_offer' => $item['price_offer'],
                ]);
            }

            // Send notification to buyer company
            $buyerCompany = $rfq->company;
            if ($buyerCompany) {
                $buyerCompany->notify(new DatabaseNotification(
                    'Proposal Baru Diterima',
                    "Vendor {$vendorCompany->name} telah mengirimkan proposal untuk RFQ \"{$rfq->title}\"",
                    "/rfq/{$rfq->id}",
                    null,
                    ['type' => 'proposal_submitted']
                ));
                Log::info("SubmitProposalAction: Sent notification to buyer company", [
                    'company_id' => $buyerCompany->id
                ]);
                
                // Send notifications to buyer company users
                $buyerCompany->load('users');
                foreach ($buyerCompany->users as $buyerUser) {
                    Log::info("SubmitProposalAction: Sending notification to buyer user", [
                        'user_id' => $buyerUser->id,
                        'user_email' => $buyerUser->email
                    ]);
                    $this->broadcastAction->execute(
                        'Proposal Baru Diterima',
                        "Vendor {$vendorCompany->name} telah mengirimkan proposal untuk RFQ \"{$rfq->title}\"",
                        "test-channel",
                        true,
                        $buyerUser->id,
                        "/rfq/{$rfq->id}",
                        ['type' => 'proposal_submitted']
                    );
                }
            }

            return $proposal;
        });
    }

    /**
     * Check if RFQ tender deadline has expired
     */
    private function isTenderExpired(Rfq $rfq): bool
    {
        if (!$rfq->approved_at) {
            return false; // If not approved yet, not expired
        }

        $duration = $rfq->duration_days ?? 7;
        $endDate = $rfq->approved_at->copy()->addDays($duration);
        
        return now() > $endDate;
    }
}
