<?php

namespace App\Domain\AI\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * GoogleSearchService
 *
 * Wrapper untuk Google Custom Search JSON API.
 * Digunakan oleh AgenticProcurementService untuk menemukan:
 *   1. Produk & spesifikasi teknis di internet
 *   2. Harga pasar terkini dari toko online / distributor resmi
 *   3. Review & perbandingan produk dari sumber terpercaya
 *
 * Konfigurasi:
 *   GOOGLE_SEARCH_API_KEY  — API Key dari Google Cloud Console
 *   GOOGLE_SEARCH_CX       — Custom Search Engine ID (cx)
 */
class GoogleSearchService
{
    private const ENDPOINT = 'https://www.googleapis.com/customsearch/v1';
    private const CACHE_TTL = 3600; // 1 jam

    private string $apiKey;
    private string $cx;
    private int $timeout;

    public function __construct()
    {
        $this->apiKey  = config('ai.google_search_api_key', env('GOOGLE_SEARCH_API_KEY', ''));
        $this->cx      = config('ai.google_search_cx', env('GOOGLE_SEARCH_CX', ''));
        $this->timeout = (int) config('ai.timeout', 30);
    }

    /**
     * Apakah Google Search dikonfigurasi dan siap digunakan.
     */
    public function isEnabled(): bool
    {
        return !empty($this->apiKey) && !empty($this->cx);
    }

    /**
     * Cari produk di internet berdasarkan query.
     *
     * @param  string $query     Query pencarian
     * @param  int    $limit     Jumlah hasil maksimal (1-10)
     * @return array             Array hasil dengan fields: title, link, snippet, price, source
     */
    public function searchProducts(string $query, int $limit = 5): array
    {
        if (!$this->isEnabled()) {
            Log::debug('GoogleSearchService: API key or CX not configured, skipping search.');
            return [];
        }

        $cacheKey = 'gsearch_' . md5($query . $limit);
        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($query, $limit) {
            return $this->fetchSearch($query, $limit);
        });
    }

    /**
     * Cari harga pasar terkini untuk satu item/produk.
     *
     * @param  string $itemName   Nama produk/barang
     * @param  string $brand      Merk (optional)
     * @param  string $specs      Spesifikasi singkat (optional)
     * @return array              [min_price, max_price, avg_price, sources, raw_results]
     */
    public function searchMarketPrice(string $itemName, string $brand = '', string $specs = ''): array
    {
        $queryParts = array_filter([$brand, $itemName, $specs, 'harga', 'Indonesia', 'distributor resmi']);
        $query      = implode(' ', $queryParts);

        $results = $this->searchProducts($query, 10);

        if (empty($results)) {
            return [
                'min_price'   => null,
                'max_price'   => null,
                'avg_price'   => null,
                'sources'     => [],
                'raw_results' => [],
            ];
        }

        $prices  = [];
        $sources = [];

        foreach ($results as $r) {
            $extracted = $this->extractPriceFromText($r['snippet'] ?? '');
            if ($extracted > 0) {
                $prices[]  = $extracted;
                $sources[] = [
                    'title'  => $r['title'],
                    'link'   => $r['link'],
                    'price'  => $extracted,
                ];
            }
        }

        if (empty($prices)) {
            return [
                'min_price'   => null,
                'max_price'   => null,
                'avg_price'   => null,
                'sources'     => [],
                'raw_results' => $results,
            ];
        }

        return [
            'min_price'   => min($prices),
            'max_price'   => max($prices),
            'avg_price'   => round(array_sum($prices) / count($prices)),
            'sources'     => $sources,
            'raw_results' => $results,
        ];
    }

    /**
     * Cari spesifikasi teknis untuk beberapa item sekaligus.
     *
     * @param  array $items  [['name' => ..., 'brand' => ..., 'category' => ...], ...]
     * @return array         Keyed by item name: hasil searchProducts
     */
    public function searchProductSpecs(array $items): array
    {
        $results = [];

        foreach ($items as $item) {
            $name     = $item['name']     ?? '';
            $brand    = $item['brand']    ?? '';
            $category = $item['category'] ?? '';

            if (empty($name)) continue;

            $query = trim("{$brand} {$name} {$category} spesifikasi teknis B2B Indonesia");
            $results[strtolower(trim($name))] = [
                'query'   => $query,
                'results' => $this->searchProducts($query, 5),
            ];
        }

        return $results;
    }

    /**
     * Cari alternatif produk dari internet berdasarkan kategori & kebutuhan.
     *
     * @param  string $productCategory   Jenis produk (misal: "laptop gaming")
     * @param  array  $requirements      Spesifikasi yang dibutuhkan
     * @return array                     Daftar produk alternatif dari web
     */
    public function searchProductAlternatives(string $productCategory, array $requirements = []): array
    {
        $specsHint = implode(' ', array_slice(array_values($requirements), 0, 3));
        $query     = trim("rekomendasi {$productCategory} terbaik {$specsHint} harga B2B Indonesia 2025 2026");

        return $this->searchProducts($query, 8);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Panggil Google Custom Search JSON API.
     */
    private function fetchSearch(string $query, int $limit): array
    {
        try {
            $response = Http::timeout($this->timeout)
                ->retry(2, 1000)
                ->get(self::ENDPOINT, [
                    'key'  => $this->apiKey,
                    'cx'   => $this->cx,
                    'q'    => $query,
                    'num'  => min($limit, 10),
                    'hl'   => 'id',
                    'gl'   => 'id',
                ]);

            if (!$response->successful()) {
                Log::warning('GoogleSearchService: API request failed', [
                    'status' => $response->status(),
                    'query'  => $query,
                ]);
                return [];
            }

            $items = $response->json('items', []);

            return array_map(function ($item) {
                return [
                    'title'     => $item['title']   ?? '',
                    'link'      => $item['link']    ?? '',
                    'snippet'   => $item['snippet'] ?? '',
                    'source'    => parse_url($item['link'] ?? '', PHP_URL_HOST) ?: '',
                    'thumbnail' => $item['pagemap']['cse_image'][0]['src'] ?? null,
                    'price'     => $this->extractPriceFromText($item['snippet'] ?? ''),
                ];
            }, $items);

        } catch (\Throwable $e) {
            Log::error('GoogleSearchService: fetchSearch exception', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Ekstrak harga rupiah dari teks (snippet / judul halaman web).
     * Mendukung: Rp 1.234.567 | IDR 1,234,567 | standalone besar
     */
    private function extractPriceFromText(string $text): float
    {
        // Format: Rp 1.234.567 atau IDR 1,234,567
        if (preg_match('/(?:rp\.?|idr\.?)\s*([\d.,]+)/i', $text, $m)) {
            $num = preg_replace('/[.,](?=\d{3})/', '', $m[1]);
            $num = str_replace(',', '.', $num);
            $val = (float) $num;
            if ($val >= 1000 && $val <= 10_000_000_000) {
                return $val;
            }
        }

        // Format angka besar standalone (misal: 15.000.000)
        if (preg_match('/\b([\d]{1,3}(?:[.,]\d{3}){2,})\b/', $text, $m)) {
            $num = preg_replace('/[.,](?=\d{3})/', '', $m[1]);
            $val = (float) $num;
            if ($val >= 100_000 && $val <= 10_000_000_000) {
                return $val;
            }
        }

        return 0;
    }
}
