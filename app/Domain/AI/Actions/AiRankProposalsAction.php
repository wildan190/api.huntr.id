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
            'submitted_at'     => $p->created_at?->toDateString(),
        ])->toArray();

        $aiResult = $this->genkit->rankProposals($proposals, $rfqContext);

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
}
