<?php

namespace App\Domain\Catalogue\Actions;

use App\Domain\AI\Services\GenkitService;
use App\Domain\Catalogue\Models\SearchLog;
use Illuminate\Support\Facades\Cache;

/**
 * GetTrendingSearchAction
 *
 * Menggabungkan data frekuensi pencarian dari DB (SearchLog)
 * dengan enrichment tren eksternal via AI (Gemini/Genkit).
 */
class GetTrendingSearchAction
{
    // Cache TTL: 30 menit agar tidak membebani Gemini API
    private const CACHE_TTL = 1800;
    private const CACHE_KEY = 'trending_searches_v1';

    public function __construct(
        private readonly GenkitService $genkit
    ) {}

    /**
     * @param  int  $limit  Jumlah keyword trending yang dikembalikan
     * @param  int  $days   Window waktu pencarian (default 30 hari terakhir)
     * @return array
     */
    public function execute(int $limit = 10, int $days = 30): array
    {
        $cacheKey = self::CACHE_KEY . "_{$limit}_{$days}";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($limit, $days) {
            // 1. Ambil keyword paling sering dicari dari DB
            $dbKeywords = SearchLog::query()
                ->selectRaw('LOWER(keyword) as keyword, COUNT(*) as count')
                ->where('searched_at', '>=', now()->subDays($days))
                ->groupByRaw('LOWER(keyword)')
                ->orderByDesc('count')
                ->limit($limit)
                ->get()
                ->map(fn($row) => [
                    'keyword' => $row->keyword,
                    'count'   => (int) $row->count,
                ])
                ->toArray();

            if (empty($dbKeywords)) {
                return [
                    'keywords'    => [],
                    'enriched_by' => 'none',
                    'period_days' => $days,
                    'generated_at' => now()->toISOString(),
                ];
            }

            // 2. Enrich dengan AI insight + trend classification
            $enriched = $this->genkit->getTrendingKeywords($dbKeywords);

            // 3. Hitung max count untuk normalisasi progress bar di frontend
            $maxCount = max(array_column($enriched, 'count') ?: [1]);

            $keywords = array_map(fn($item) => array_merge($item, [
                'percentage' => $maxCount > 0 ? round(($item['count'] / $maxCount) * 100) : 0,
            ]), $enriched);

            return [
                'keywords'     => $keywords,
                'enriched_by'  => 'gemini',
                'period_days'  => $days,
                'generated_at' => now()->toISOString(),
            ];
        });
    }
}
