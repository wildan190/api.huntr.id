<?php

namespace App\Domain\AI\Actions;

use App\Domain\AI\Services\OpenAiService;
use App\Domain\Catalogue\Models\Catalogue;
use App\Domain\Catalogue\Models\SearchLog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * AiSearchCatalogueAction
 *
 * Menggunakan AI OpenAI untuk memahami natural language query dari user,
 * kemudian mencari produk yang relevan di katalog.
 */
class AiSearchCatalogueAction
{
    public function __construct(
        private readonly OpenAiService $openAi
    ) {}

    /**
     * @param string $query Natural language query dari user
     * @param array $params Parameter tambahan (company_id, dll)
     * @return array { intent, products, ai_summary, is_ai_search }
     */
    public function execute(string $query, array $params = []): array
    {
        // 1. Log pencarian untuk analitik frekuensi
        SearchLog::record($query, 'ai', $params['company_id'] ?? null);

        // 2. Extract intent dari natural language menggunakan OpenAI
        $intent = $this->openAi->extractSearchIntent($query);

        $comparisonAnalysis = null;
        if (!empty($intent['is_comparison'])) {
            try {
                $comparisonAnalysis = $this->openAi->generateComparisonText($query);
            } catch (\Exception $e) {
                Log::error('generateComparisonText failed', ['error' => $e->getMessage()]);
            }
        }
        $intent['comparison_analysis'] = $comparisonAnalysis;

        // 3. Build DB query berdasarkan intent
        $dbQuery = Catalogue::query()->with('company');

        // Filter hanya vendor yang approved/pending
        $dbQuery->whereHas('company', function ($q) {
            $q->where('type', 'vendor')
              ->whereIn('status', ['approved', 'pending']);
        });

        // Filter by company_id jika ada
        if (!empty($params['company_id'])) {
            $dbQuery->where('company_id', $params['company_id']);
        }

        // Apply keyword search dari AI intent
        $keywords = $intent['keywords'] ?? [];
        $category = $intent['category'] ?? null;
        $brand    = $intent['brand'] ?? null;

        $operator = $dbQuery->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

        if (!empty($keywords)) {
            $dbQuery->where(function ($q) use ($keywords, $category, $brand, $operator) {
                foreach ($keywords as $kw) {
                    $q->orWhere('name', $operator, "%{$kw}%")
                      ->orWhere('item_code', $operator, "%{$kw}%")
                      ->orWhere('specifications', $operator, "%{$kw}%");
                }
                if ($category) {
                    $q->orWhere('category', $operator, "%{$category}%");
                }
                if ($brand) {
                    $q->orWhere('brand', $operator, "%{$brand}%");
                }
            });
        }

        // Filter by category jika ada dari intent
        if ($category && empty($keywords)) {
            $dbQuery->where('category', $operator, "%{$category}%");
        }

        $products = $dbQuery->limit(30)->get();

        // 4. AI Re-ranking: Send DB candidates to OpenAI to evaluate exact match & scores
        try {
            $candidates   = $products->toArray();
            $companyId    = $params['company_id'] ?? null;
            $aiRankings   = $this->openAi->rankSearchProducts($query, $candidates, $companyId);
            $rankingsById = collect($aiRankings)->keyBy('product_id');

            $rankedProducts = $products->map(function ($product) use ($rankingsById) {
                $rankInfo = $rankingsById->get($product->id);
                $product->ai_match       = $rankInfo['is_match'] ?? true;
                $product->ai_score       = (int) ($rankInfo['relevance_score'] ?? 50);
                $product->ai_explanation = $rankInfo['fit_reason'] ?? ($rankInfo['explanation'] ?? null);
                $product->estimated_price= !empty($rankInfo['estimated_unit_price_idr']) && $rankInfo['estimated_unit_price_idr'] > 0
                    ? (float) $rankInfo['estimated_unit_price_idr']
                    : 0;
                return $product;
            });

            // Filter produk yang match, fallback ke semua jika tidak ada yang match
            $matched = $rankedProducts->filter(fn($p) => ($p->ai_match ?? true) === true);
            $finalProducts = $matched->isNotEmpty() ? $matched : $rankedProducts;

            // Sort by AI score descending — values() menjamin integer keys dari 0
            $products = $finalProducts->sortByDesc('ai_score')->values();
        } catch (\Exception $e) {
            Log::warning('AI Re-ranking failed, falling back to database order', ['error' => $e->getMessage()]);
        }

        return [
            'is_ai_search' => true,
            'intent'       => $intent,
            'ai_summary'   => $intent['ai_summary'] ?? $query,
            'products'     => $products,
            'total'        => $products->count(),
        ];
    }
}
