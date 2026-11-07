<?php

namespace App\Domain\Rfq\Services;

use App\Domain\Order\Models\HistoricalPoItem;
use App\Domain\Rfq\Models\RfqItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ItemPriceHistoryService
{
    /**
     * Resolve the last purchase price for an RFQ item based on:
     * Priority 1: Historical last purchase price from previous purchases (HistoricalPoItem or awarded ProposalItem)
     * Priority 2: Target price from PR (estimated_price)
     * Priority 3: 0 if neither source has data
     *
     * @return array{price: float, source: 'historical'|'target_price'|'none', date: string|null}
     */
    public function resolveLastPrice(RfqItem $item, string $buyerCompanyId, ?string $currentRfqId = null): array
    {
        $sku = trim((string) ($item->sku ?: $item->catalogue?->sku ?: ''));
        $name = trim((string) ($item->catalogue?->name ?: $item->item_name ?: ''));
        $catalogueId = $item->catalogue_id;

        $operator = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

        $historicalCandidates = [];

        // 1a. Query historical_po_items linked to buyer_company_id
        if ($sku !== '' || $name !== '') {
            try {
                $historicalPoQuery = HistoricalPoItem::query()
                    ->whereHas('purchaseOrder', function ($q) use ($buyerCompanyId) {
                        $q->where('buyer_company_id', $buyerCompanyId);
                    })
                    ->where('unit_price', '>', 0)
                    ->where(function ($q) use ($sku, $name, $operator) {
                        if ($sku !== '') {
                            $q->where('inventory_code', $sku);
                        }
                        if ($name !== '') {
                            if ($sku !== '') {
                                $q->orWhere('inventory_name', $operator, '%' . addcslashes($name, '%_\\') . '%');
                            } else {
                                $q->where('inventory_name', $operator, '%' . addcslashes($name, '%_\\') . '%');
                            }
                        }
                    })
                    ->orderByDesc('order_date')
                    ->orderByDesc('created_at')
                    ->first(['unit_price', 'order_date', 'created_at']);

                if ($historicalPoQuery) {
                    $date = $historicalPoQuery->order_date ?? $historicalPoQuery->created_at;
                    $historicalCandidates[] = [
                        'price' => (float) $historicalPoQuery->unit_price,
                        'date'  => $date ? Carbon::parse($date) : null,
                    ];
                }
            } catch (\Throwable $e) {
                Log::warning('ItemPriceHistoryService: HistoricalPoItem query failed', ['error' => $e->getMessage()]);
            }
        }

        // 1b. Query previous awarded proposal_items for the same buyer company
        if ($catalogueId || $sku !== '' || $name !== '') {
            try {
                $awardedProposalQuery = DB::table('proposal_items as pi')
                    ->join('proposals as p', 'p.id', '=', 'pi.proposal_id')
                    ->join('rfqs as r', 'r.id', '=', 'p.rfq_id')
                    ->join('rfq_items as ri', 'ri.id', '=', 'pi.rfq_item_id')
                    ->where('r.company_id', $buyerCompanyId)
                    ->when($currentRfqId, function ($q) use ($currentRfqId) {
                        $q->where('r.id', '!=', $currentRfqId);
                    })
                    ->where(function ($q) {
                        $q->where('pi.is_awarded', true)
                          ->orWhereIn('p.winner_status', ['awarded', 'approved', 'winner']);
                    })
                    ->where('pi.price_offer', '>', 0)
                    ->where(function ($q) use ($catalogueId, $sku, $name, $operator) {
                        $applied = false;
                        if ($catalogueId) {
                            $q->where('ri.catalogue_id', $catalogueId);
                            $applied = true;
                        }
                        if ($sku !== '') {
                            if ($applied) {
                                $q->orWhere('ri.sku', $sku);
                            } else {
                                $q->where('ri.sku', $sku);
                                $applied = true;
                            }
                        }
                        if ($name !== '') {
                            if ($applied) {
                                $q->orWhere('ri.item_name', $operator, '%' . addcslashes($name, '%_\\') . '%');
                            } else {
                                $q->where('ri.item_name', $operator, '%' . addcslashes($name, '%_\\') . '%');
                            }
                        }
                    })
                    ->orderByDesc('p.awarded_at')
                    ->orderByDesc('p.created_at')
                    ->select(['pi.price_offer', 'p.awarded_at', 'p.created_at'])
                    ->first();

                if ($awardedProposalQuery) {
                    $date = $awardedProposalQuery->awarded_at ?? $awardedProposalQuery->created_at;
                    $historicalCandidates[] = [
                        'price' => (float) $awardedProposalQuery->price_offer,
                        'date'  => $date ? Carbon::parse($date) : null,
                    ];
                }
            } catch (\Throwable $e) {
                Log::warning('ItemPriceHistoryService: Awarded proposal query failed', ['error' => $e->getMessage()]);
            }
        }

        // If we found any historical purchases, pick the most recent one
        if (!empty($historicalCandidates)) {
            usort($historicalCandidates, function ($a, $b) {
                $timeA = $a['date'] ? $a['date']->timestamp : 0;
                $timeB = $b['date'] ? $b['date']->timestamp : 0;
                return $timeB <=> $timeA;
            });

            $best = $historicalCandidates[0];
            return [
                'price'  => $best['price'],
                'source' => 'historical',
                'date'   => $best['date'] ? $best['date']->toDateString() : null,
            ];
        }

        // Priority 2: Target price from PR item (estimated_price)
        $estimatedPrice = (float) ($item->estimated_price ?? 0);
        if ($estimatedPrice > 0) {
            return [
                'price'  => $estimatedPrice,
                'source' => 'target_price',
                'date'   => null,
            ];
        }

        // Priority 3: 0 if no data
        return [
            'price'  => 0.0,
            'source' => 'none',
            'date'   => null,
        ];
    }
}

