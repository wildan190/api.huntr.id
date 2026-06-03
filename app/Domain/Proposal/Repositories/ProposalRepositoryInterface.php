<?php

namespace App\Domain\Proposal\Repositories;

use App\Domain\Company\Models\Company;
use App\Domain\Proposal\Models\Proposal;
use App\Domain\Rfq\Models\Rfq;
use Illuminate\Database\Eloquent\Collection;

interface ProposalRepositoryInterface
{
    /**
     * Create a new proposal record.
     *
     * @param array $data
     * @return Proposal
     */
    public function create(array $data): Proposal;

    /**
     * Update proposal attributes.
     *
     * @param Proposal $proposal
     * @param array $data
     * @return Proposal
     */
    public function update(Proposal $proposal, array $data): Proposal;

    /**
     * Get all submitted proposals for a given RFQ.
     *
     * @param Rfq $rfq
     * @return Collection
     */
    public function getSubmittedByRfq(Rfq $rfq): Collection;

    /**
     * Determine whether the vendor has already submitted a proposal for the RFQ.
     *
     * @param Rfq $rfq
     * @param Company $company
     * @return bool
     */
    public function hasSubmittedForRfq(Rfq $rfq, Company $company): bool;

    /**
     * Reject all proposals for an RFQ except the winning proposal.
     *
     * @param Rfq $rfq
     * @param int $winningProposalId
     * @return void
     */
    public function rejectOthers(Rfq $rfq, string $winningProposalId): void;
}
