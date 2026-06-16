<?php

namespace App\Domain\AI\Actions;

use App\Domain\AI\Services\GenkitService;
use App\Domain\Rfq\Models\Rfq;

/**
 * AiRankProposalsAction
 *
 * Memberikan AI assessment/ranking proposal tender dengan analisis multikriteria
 * dan reasoning yang lebih mendalam dari sekadar sorting harga.
 */
class AiRankProposalsAction
{
    public function __construct(
        private readonly GenkitService $genkit
    ) {}

    /**
     * @param string $rfqId UUID of the RFQ
     * @return array AI ranking result
     */
    public function execute(string $rfqId): array
    {
        $rfq = Rfq::with([
            'items.catalogue',
            'company',
            'proposals' => function ($q) {
                $q->with(['company', 'items.rfqItem.catalogue']);
            }
        ])->findOrFail($rfqId);

        if ($rfq->proposals->isEmpty()) {
            return [
                'rankings'          => [],
                'overall_analysis'  => 'Belum ada proposal yang masuk untuk RFQ ini.',
                'recommended_winner_id' => null,
            ];
        }

        // Build context untuk AI
        $rfqContext = [
            'id'            => $rfq->id,
            'title'         => $rfq->title,
            'description'   => $rfq->description,
            'duration_days' => $rfq->duration_days,
            'items'         => $rfq->items->map(fn($item) => [
                'product'  => $item->catalogue?->name,
                'category' => $item->catalogue?->category,
                'qty'      => $item->qty,
                'uom'      => $item->catalogue?->uom,
                'estimated_price' => $item->estimated_price,
            ])->toArray(),
        ];

        $proposals = $rfq->proposals->map(fn($p) => [
            'id'               => $p->id,
            'vendor'           => $p->company?->name,
            'price_offer'      => $p->price_offer,
            'delivery_days'    => $p->delivery_days,
            'warranty_months'  => $p->warranty_months,
            'payment_term'     => $p->payment_term,
            'status'           => $p->status,
            'winner_status'    => $p->winner_status,
            'items_count'      => $p->items->count(),
            'rfq_items_count'  => $rfq->items->count(),
            'submitted_at'     => $p->created_at?->toDateString(),
        ])->toArray();

        try {
            $aiResult = $this->genkit->rankProposals($proposals, $rfqContext);
            
            if (empty($aiResult['rankings'])) {
                $aiResult = $this->calculateFallbackRankings($proposals);
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('AiRankProposalsAction: Using fallback ranking', ['error' => $e->getMessage()]);
            $aiResult = $this->calculateFallbackRankings($proposals);
        }

        // Merge dengan data proposal asli untuk memudahkan frontend
        $rankingsWithData = collect($aiResult['rankings'] ?? [])->map(function ($rank) use ($rfq) {
            $proposal = $rfq->proposals->firstWhere('id', $rank['proposal_id']);
            return array_merge($rank, [
                'proposal' => $proposal ? [
                    'id'              => $proposal->id,
                    'price_offer'     => $proposal->price_offer,
                    'delivery_days'   => $proposal->delivery_days,
                    'warranty_months' => $proposal->warranty_months,
                    'payment_term'    => $proposal->payment_term,
                    'winner_status'   => $proposal->winner_status,
                    'company'         => ['name' => $proposal->company?->name],
                ] : null,
            ]);
        })->values()->toArray();

        return [
            'rankings'              => $rankingsWithData,
            'overall_analysis'      => $aiResult['overall_analysis'] ?? '',
            'recommended_winner_id' => $aiResult['recommended_winner_id'] ?? null,
            'rfq_title'             => $rfq->title,
        ];
    }

    /**
     * Calculate fallback rankings using a formula-based approach without AI
     */
    private function calculateFallbackRankings(array $proposals): array
    {
        // Normalize data
        $prices = collect($proposals)->pluck('price_offer')->filter()->values();
        $minPrice = $prices->min() ?? 1;
        
        $deliveryDays = collect($proposals)->pluck('delivery_days')->filter()->values();
        $minDelivery = $deliveryDays->min() ?? 1;
        
        $warrantyMonths = collect($proposals)->pluck('warranty_months')->filter()->values();
        $maxWarranty = $warrantyMonths->max() ?? 1;

        $rankedProposals = collect($proposals)->map(function ($p) use ($minPrice, $minDelivery, $maxWarranty) {
            // Calculate scores (0-100)
            $priceScore = $minPrice / max($p['price_offer'] ?? $minPrice, 1) * 100;
            $deliveryScore = $minDelivery / max($p['delivery_days'] ?? $minDelivery, 1) * 100;
            $warrantyScore = max($p['warranty_months'] ?? 0, 1) / max($maxWarranty, 1) * 100;
            $completenessScore = min(($p['items_count'] / max($p['rfq_items_count'], 1)) * 100, 100);

            $totalScore = ($priceScore * 0.4) + ($deliveryScore * 0.3) + ($warrantyScore * 0.2) + ($completenessScore * 0.1);

            $strengths = [];
            $weaknesses = [];
            if ($priceScore >= 80) $strengths[] = "Penawaran harga sangat kompetitif";
            elseif ($priceScore < 50) $weaknesses[] = "Harga penawaran relatif tinggi";

            if ($deliveryScore >= 80) $strengths[] = "Waktu pengiriman cepat";
            elseif ($deliveryScore < 50) $weaknesses[] = "Waktu pengiriman relatif lama";

            if ($warrantyScore >= 80) $strengths[] = "Masa garansi panjang";
            elseif ($warrantyScore < 50) $weaknesses[] = "Masa garansi relatif pendek";

            if ($completenessScore >= 80) $strengths[] = "Kelengkapan item proposal baik";
            elseif ($completenessScore < 50) $weaknesses[] = "Kelengkapan item proposal perlu ditingkatkan";

            if (empty($strengths)) $strengths[] = "Proposal memenuhi syarat dasar";
            if (empty($weaknesses)) $weaknesses[] = "Tidak ada kelemahan signifikan";

            $recommendation = $totalScore >= 80 
                ? "Vendor ini sangat direkomendasikan dengan kombinasi harga, waktu pengiriman, dan garansi yang optimal" 
                : ($totalScore >= 60 
                    ? "Vendor ini memenuhi syarat dasar, namun bisa dipertimbangkan negosiasi untuk meningkatkan value" 
                    : "Vendor ini kurang optimal dibandingkan kandidat lain, sebaiknya lakukan negosiasi terlebih dahulu");

            return [
                'proposal_id' => $p['id'],
                'rank' => 0,
                'total_score' => round($totalScore, 1),
                'score_breakdown' => [
                    'price_score' => round($priceScore),
                    'delivery_score' => round($deliveryScore),
                    'warranty_score' => round($warrantyScore),
                    'completeness_score' => round($completenessScore),
                ],
                'recommendation' => $recommendation,
                'strengths' => $strengths,
                'weaknesses' => $weaknesses,
            ];
        })->sortByDesc('total_score')->values()->map(function ($item, $index) {
            $item['rank'] = $index + 1;
            return $item;
        })->toArray();

        $overallAnalysis = "Analisis multikriteria menunjukkan bahwa terdapat " . count($rankedProposals) . " proposal yang dievaluasi. Vendor dengan peringkat teratas menunjukkan kombinasi terbaik antara harga (40%), waktu pengiriman (30%), garansi (20%), dan kelengkapan item (10%).";

        return [
            'rankings' => $rankedProposals,
            'overall_analysis' => $overallAnalysis,
            'recommended_winner_id' => $rankedProposals[0]['proposal_id'] ?? null,
        ];
    }
}
