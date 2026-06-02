<?php

namespace App\Domain\Proposal\Repositories;

use App\Domain\Company\Models\Company;
use App\Domain\Proposal\Models\Proposal;
use App\Domain\Rfq\Models\Rfq;
use Illuminate\Database\Eloquent\Collection;

class EloquentProposalRepository implements ProposalRepositoryInterface
{
    /**
     * {@inheritdoc}
     */
    public function create(array $data): Proposal
    {
        return Proposal::create($data);
    }

    /**
     * {@inheritdoc}
     */
    public function update(Proposal $proposal, array $data): Proposal
    {
        $proposal->update($data);

        return $proposal->fresh();
    }

    /**
     * {@inheritdoc}
     */
    public function getSubmittedByRfq(Rfq $rfq): Collection
    {
        return $rfq->proposals()->where('status', 'submitted')->get();
    }

    public function hasSubmittedForRfq(Rfq $rfq, Company $company): bool
    {
        return $rfq->proposals()
            ->where('company_id', $company->id)
            ->exists();
    }

    /**
     * {@inheritdoc}
     */
    public function rejectOthers(Rfq $rfq, int $winningProposalId): void
    {
        $rfq->proposals()
            ->where('id', '!=', $winningProposalId)
            ->update(['status' => 'rejected']);
    }
}
