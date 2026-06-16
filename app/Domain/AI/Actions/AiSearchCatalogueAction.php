<?php

namespace App\Domain\AI\Actions;

use App\Domain\AI\Services\GenkitService;
use App\Domain\Catalogue\Models\Catalogue;
use Illuminate\Support\Collection;

/**
 * AiSearchCatalogueAction
 *
 * Menggunakan AI untuk memahami natural language query dari user,
 * kemudian mencari produk yang relevan di katalog.
 */
class AiSearchCatalogueAction
{
    public function __construct(
        private readonly GenkitService $genkit
    ) {}

    /**
     * @param string $query Natural language query dari user
     * @param array $params Parameter tambahan (company_id, dll)
     * @return array { intent, products, ai_summary, is_ai_search }
     */
    public function execute(string $query, array $params = []): array
    {
        // 1. Extract intent dari natural language
        $intent = $this->genkit->extractSearchIntent($query);

        $comparisonAnalysis = null;
        if (!empty($intent['is_comparison'])) {
            try {
                $comparisonAnalysis = $this->genkit->generateComparisonText($query);
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('generateComparisonText failed', ['error' => $e->getMessage()]);
            }
        }
        $intent['comparison_analysis'] = $comparisonAnalysis;

        // 2. Build DB query berdasarkan intent
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

        // 3. AI Re-ranking: Send DB candidates to Gemini to evaluate exact match & scores
        try {
            $candidates = $products->toArray();
            $aiRankings = $this->genkit->rankSearchProducts($query, $candidates);

            $rankedProducts = $products->map(function ($product) use ($aiRankings) {
                $rankInfo = collect($aiRankings)->firstWhere('product_id', $product->id);
                $product->ai_match = $rankInfo['is_match'] ?? true;
                $product->ai_score = $rankInfo['relevance_score'] ?? 50;
                $product->ai_explanation = $rankInfo['explanation'] ?? null;
                return $product;
            });

            // Sort products by AI relevance score descending
            $products = $rankedProducts->sortByDesc('ai_score')->values();
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('AI Re-ranking failed, falling back to database order', ['error' => $e->getMessage()]);
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
