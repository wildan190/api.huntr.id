<?php

namespace App\Domain\Proposal\Actions;

use App\Domain\Proposal\Repositories\ProposalRepositoryInterface;
use App\Domain\Rfq\Models\Rfq;

class CalculateSawRankingAction
{
    public function __construct(
        private readonly ProposalRepositoryInterface $proposalRepository
    ) {}

    /**
     * Calculate Simple Additive Weighting (SAW) rankings for RFQ proposals.
     *
     * Weights: Price (50% - Cost), Delivery (30% - Cost), Warranty (20% - Benefit)
     *
     * @param Rfq $rfq Target RFQ
     * @return array Ranked proposals list with scores: [['proposal' => $p, 'score' => $score], ...]
     */
    public function execute(Rfq $rfq): array
    {
        $proposals = $this->proposalRepository->getSubmittedByRfq($rfq);

        if ($proposals->isEmpty()) {
            return [];
        }

        // 1. Find Min and Max values for normalization
        $minPrice    = $proposals->min('price_offer');
        $minDelivery = $proposals->min('delivery_days');
        $maxWarranty = $proposals->max('warranty_months');

        // Prevent division by zero
        $minPrice    = $minPrice    > 0 ? $minPrice    : 1;
        $minDelivery = $minDelivery > 0 ? $minDelivery : 1;
        $maxWarranty = $maxWarranty > 0 ? $maxWarranty : 1;

        $rankings = [];

        // 2. Normalize and calculate final SAW score for each proposal
        foreach ($proposals as $proposal) {
            // Price: Cost criterion (lower price -> higher normalized score)
            $normPrice    = $minPrice / $proposal->price_offer;

            // Delivery: Cost criterion (lower days -> higher normalized score)
            $normDelivery = $minDelivery / $proposal->delivery_days;

            // Warranty: Benefit criterion (higher months -> higher normalized score)
            $normWarranty = $proposal->warranty_months / $maxWarranty;

            $score = (0.50 * $normPrice) + (0.30 * $normDelivery) + (0.20 * $normWarranty);

            $rankings[] = [
                'proposal' => $proposal,
                'score'    => round($score, 4),
            ];
        }

        // 3. Sort rankings descending by score
        usort($rankings, fn($a, $b) => $b['score'] <=> $a['score']);

        return $rankings;
    }
}
