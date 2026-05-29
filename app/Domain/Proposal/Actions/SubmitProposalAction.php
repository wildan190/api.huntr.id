<?php

namespace App\Domain\Proposal\Actions;

use App\Domain\Proposal\Repositories\ProposalRepositoryInterface;
use App\Domain\Company\Models\Company;
use App\Domain\Proposal\Models\Proposal;
use App\Domain\Rfq\Models\Rfq;
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
     * @param array $data Input fields: price_offer, delivery_days, warranty_months
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

        return $this->proposalRepository->create([
            'rfq_id'          => $rfq->id,
            'company_id'      => $vendorCompany->id,
            'price_offer'     => $data['price_offer'],
            'delivery_days'   => $data['delivery_days'],
            'warranty_months' => $data['warranty_months'] ?? 12,
            'status'          => 'submitted',
        ]);
    }
}
