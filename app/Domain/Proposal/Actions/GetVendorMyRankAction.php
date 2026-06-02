<?php

namespace App\Domain\Proposal\Actions;

use App\Domain\Company\Models\Company;
use App\Domain\Proposal\Models\Proposal;
use App\Domain\Rfq\Models\Rfq;

class GetVendorMyRankAction
{
    public function __construct(
        private readonly CalculateVendorRankingAction $calculateRankingAction
    ) {}

    /**
     * Get all rankings for a vendor across all RFQs they participated in.
     */
    public function execute(Company $vendor): array
    {
        // Get all proposals submitted by this vendor
        $proposals = Proposal::where('company_id', $vendor->id)
            ->with('rfq.company')
            ->get();

        $myRankings = [];
        $totalWins = 0;

        foreach ($proposals as $proposal) {
            $rfq = $proposal->rfq;
            if (!$rfq) continue;

            // Calculate rankings for this RFQ
            $allRankings = $this->calculateRankingAction->execute($rfq);
            
            // Find this vendor's rank
            $myRankData = null;
            foreach ($allRankings as $rankData) {
                if ($rankData['proposal']->id === $proposal->id) {
                    $myRankData = $rankData;
                    break;
                }
            }

            if ($myRankData) {
                if ($myRankData['is_winner']) {
                    $totalWins++;
                }

                $myRankings[] = [
                    'rfq_id' => $rfq->id,
                    'rfq_title' => $rfq->title,
                    'buyer_name' => $rfq->company->name,
                    'my_price' => $proposal->price_offer,
                    'my_rank' => $myRankData['rank'],
                    'total_participants' => count($allRankings),
                    'is_winner' => $myRankData['is_winner'],
                    'submitted_at' => $proposal->created_at,
                ];
            }
        }

        // Sort by most recent submission
        usort($myRankings, fn($a, $b) => $b['submitted_at'] <=> $a['submitted_at']);

        return [
            'total_wins' => $totalWins,
            'total_participations' => count($myRankings),
            'rankings' => $myRankings,
        ];
    }
}
