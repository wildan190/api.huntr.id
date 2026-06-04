<?php

namespace App\Domain\Proposal\Actions;

use App\Domain\Rfq\Models\Rfq;
use App\Domain\Proposal\Repositories\ProposalRepositoryInterface;

class CalculateVendorRankingAction
{
    public function __construct(
        private readonly ProposalRepositoryInterface $proposalRepository
    ) {}

    /**
     * Calculate rankings for RFQ proposals based on Lowest Price.
     *
     * @param Rfq $rfq Target RFQ
     * @return array Ranked proposals list: [['proposal' => $p, 'rank' => $rank, 'is_winner' => $bool], ...]
     */
    public function execute(Rfq $rfq): array
    {
        $proposals = $this->proposalRepository->getSubmittedByRfq($rfq);

        if ($proposals->isEmpty()) {
            return [];
        }

        // Sort by price_offer ASC (lowest price first)
        // If prices are equal, sort by delivery_days ASC, then warranty_months DESC
        $sortedProposals = $proposals->sort(function ($a, $b) {
            if ($a->price_offer != $b->price_offer) {
                return $a->price_offer <=> $b->price_offer;
            }
            if ($a->delivery_days != $b->delivery_days) {
                return $a->delivery_days <=> $b->delivery_days;
            }
            return $b->warranty_months <=> $a->warranty_months;
        })->values();

        $rankings = [];
        foreach ($sortedProposals as $index => $proposal) {
            $rank = $index + 1;
            $rankings[] = [
                'proposal' => $proposal->load(['company', 'items.rfqItem.catalogue']),
                'rank' => $rank,
                'is_winner' => in_array($proposal->winner_status, ['awarded', 'approved'], true),
                'is_top_rank' => $rank === 1,
            ];
        }

        return $rankings;
    }
}
