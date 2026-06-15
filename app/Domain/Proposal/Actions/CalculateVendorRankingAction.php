<?php

namespace App\Domain\Proposal\Actions;

use App\Domain\Rfq\Models\Rfq;
use App\Domain\Proposal\Repositories\ProposalRepositoryInterface;
use App\Domain\Proposal\Models\Proposal;

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
            
            // Calculate vendor stats
            $companyId = $proposal->company_id;
            $totalTenders = Proposal::where('company_id', $companyId)->count();
            $totalWins = Proposal::where('company_id', $companyId)
                ->whereIn('winner_status', ['awarded', 'approved'])
                ->count();
                
            // Calculate detailed reason
            $reason = $this->calculateDetailedReason($proposal, $sortedProposals, $index);
            
            $rankings[] = [
                'proposal' => $proposal->load(['company', 'items.rfqItem.catalogue']),
                'rank' => $rank,
                'is_winner' => in_array($proposal->winner_status, ['awarded', 'approved'], true),
                'is_top_rank' => $rank === 1,
                'vendor_stats' => [
                    'total_tenders' => $totalTenders,
                    'total_wins' => $totalWins,
                    'win_rate' => $totalTenders > 0 ? round(($totalWins / $totalTenders) * 100, 1) : 0,
                ],
                'detailed_reason' => $reason,
            ];
        }

        return $rankings;
    }
    
    /**
     * @param Proposal $currentProposal
     * @param \Illuminate\Support\Collection $sortedProposals
     * @param int $currentIndex
     * @return array
     */
    private function calculateDetailedReason(Proposal $currentProposal, $sortedProposals, int $currentIndex): array
    {
        $reason = [
            'price_comparison' => null,
            'delivery_comparison' => null,
            'warranty_comparison' => null,
            'summary' => '',
        ];
        
        // Compare with top rank if not top
        if ($currentIndex > 0) {
            $topProposal = $sortedProposals[0];
            $priceDiff = $currentProposal->price_offer - $topProposal->price_offer;
            $percentDiff = $topProposal->price_offer > 0 ? round(($priceDiff / $topProposal->price_offer) * 100, 1) : 0;
            
            $reason['price_comparison'] = [
                'top_price' => $topProposal->price_offer,
                'current_price' => $currentProposal->price_offer,
                'difference' => $priceDiff,
                'percent_difference' => $percentDiff,
            ];
            
            if ($priceDiff > 0) {
                $reason['summary'] = "Harga penawaran lebih tinggi {$percentDiff}% (+Rp " . number_format($priceDiff) . ") dibandingkan dengan peringkat pertama.";
            } else if ($priceDiff == 0) {
                if ($currentProposal->delivery_days > $topProposal->delivery_days) {
                    $daysDiff = $currentProposal->delivery_days - $topProposal->delivery_days;
                    $reason['delivery_comparison'] = [
                        'top_delivery' => $topProposal->delivery_days,
                        'current_delivery' => $currentProposal->delivery_days,
                        'difference' => $daysDiff,
                    ];
                    $reason['summary'] = "Harga sama, namun waktu pengiriman lebih lama {$daysDiff} hari dibandingkan dengan peringkat pertama.";
                } else if ($currentProposal->delivery_days == $topProposal->delivery_days && $currentProposal->warranty_months < $topProposal->warranty_months) {
                    $warrantyDiff = $topProposal->warranty_months - $currentProposal->warranty_months;
                    $reason['warranty_comparison'] = [
                        'top_warranty' => $topProposal->warranty_months,
                        'current_warranty' => $currentProposal->warranty_months,
                        'difference' => $warrantyDiff,
                    ];
                    $reason['summary'] = "Harga dan waktu pengiriman sama, namun garansi lebih pendek {$warrantyDiff} bulan dibandingkan dengan peringkat pertama.";
                }
            }
        } else {
            $reason['summary'] = "Peringkat pertama karena memiliki kombinasi parameter paling optimal: harga terendah, waktu pengiriman tercepat, dan garansi terpanjang (jika ada kesamaan pada parameter sebelumnya).";
        }
        
        return $reason;
    }
}
