<?php

namespace App\Domain\Proposal\Actions;

use App\Domain\Proposal\Repositories\ProposalRepositoryInterface;
use App\Domain\Company\Models\Company;
use App\Domain\Proposal\Models\Proposal;
use App\Domain\Proposal\Models\ProposalItem;
use App\Domain\Rfq\Models\Rfq;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubmitProposalAction
{
    public function __construct(
        private readonly ProposalRepositoryInterface $proposalRepository
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

            return $proposal;
        });
    }
}
