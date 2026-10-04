<?php

namespace App\Domain\AI\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * BraveSearchService
 *
 * Wrapper untuk Brave Search API (Web Search).
 * Digunakan oleh AgenticProcurementService untuk menemukan:
 *   1. Produk & spesifikasi teknis di internet
 *   2. Harga pasar terkini dari distributor resmi / B2B marketplaces
 *   3. Review & referensi produk secara real-time
 *
 * Konfigurasi:
 *   BRAVE_SEARCH_API_KEY  — API Key dari Brave Search Console (api.search.brave.com)
 */
class BraveSearchService
{
    private const ENDPOINT = 'https://api.search.brave.com/res/v1/web/search';
    private const CACHE_TTL = 3600; // 1 jam

    /**
     * Daftar domain yang diprioritaskan untuk pencarian harga & produk Indonesia.
     * Mencakup distributor resmi alat berat, marketplace B2B, dan e-commerce lokal.
     */
    private const TARGET_DOMAINS = [
        'unitedtractors.com',   // UNTR — distributor resmi Komatsu
        'sanyindonesia.co.id',  // Sany — alat berat
        'gsmarena.com',         // GSMArena — referensi gadget/elektronik
        'tokopedia.com',        // Tokopedia — marketplace utama
        'shopee.co.id',         // Shopee — marketplace
        'blibli.com',           // Blibli — marketplace
        'indotrading.com',      // Indotrading — B2B marketplace Indonesia
    ];

    private string $apiKey;
    private int $timeout;

    public function __construct()
    {
        $this->apiKey  = config('ai.brave_search_api_key', env('BRAVE_SEARCH_API_KEY', ''));
        $this->timeout = (int) config('ai.timeout', 30);
    }

    /**
     * Apakah Brave Search dikonfigurasi dan siap digunakan.
     */
    public function isEnabled(): bool
    {
        return !empty($this->apiKey);
    }

    /**
     * Cari produk di internet berdasarkan query.
     *
     * @param  string      $query         Query pencarian
     * @param  int         $limit         Jumlah hasil maksimal (1-20)
     * @param  array|null  $targetDomains Domain yang diprioritaskan (null = pakai TARGET_DOMAINS)
     * @return array                      Array hasil dengan fields: title, link, snippet, price, source, thumbnail
     */
    public function searchProducts(string $query, int $limit = 5, ?array $targetDomains = null): array
    {
        if (!$this->isEnabled()) {
            Log::debug('BraveSearchService: API key not configured, skipping search.');
            return [];
        }

        $enrichedQuery = $this->buildQueryWithDomains($query, $targetDomains);

        $cacheKey = 'bsearch_' . md5($enrichedQuery . '_' . $limit);
        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($enrichedQuery, $limit) {
            return $this->fetchSearch($enrichedQuery, $limit);
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
    public function searchMarketPrice(string $itemName, string $brand = '', string $specs = '', ?array $targetDomains = null): array
    {
        // ══════════════════════════════════════════════════════════
        // 🔥 FIX 6 — Query LEBIH SPESIFIK:
        //    - Hilangkan "distributor resmi" jika sudah ada merk biar tdk over-broad.
        //    - Tambahkan kata "harga unit satuan" dan "harga produk utama" agar
        //      hasil pencarian TIDAK ambil harga aksesoris (SSD, RAM, keyboard)
        //      yang muncul bersama listing produk utama.
        //    - Hindari query terlalu umum (misal: "harga keyboard harga ssd harga pc" → BAD)
        // ══════════════════════════════════════════════════════════
        $queryBase = array_filter([$brand, $itemName, $specs]);
        $base = implode(' ', $queryBase);
        $specificity = '';
        if (!empty($base) && strlen($base) > 12) {
            // Jika query cukup spesifik (tidak cuma "PC" atau "Laptop"), tambahkan
            // kata "harga unit produk lengkap" agar tidak ambil listing aksesoris satuan.
            $specificity = 'harga unit produk utama satuan';
        } else {
            $specificity = 'harga Indonesia';
        }
        $query = trim("{$base} {$specificity} site:tokopedia.com OR site:blibli.com OR site:shopee.co.id OR site:bhinneka.com OR site:indotrading.com");

        $results = $this->searchProducts($query, 12, $targetDomains);

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
            $extracted = $this->extractPriceFromText(($r['title'] ?? '') . ' ' . ($r['snippet'] ?? ''));
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

        // ══════════════════════════════════════════════════════════
        // 🔥 OUTLIER FILTERING (IQR Method):
        //    Setelah daftar harga terkumpul, BUANG harga outlier
        //    (terlalu murah / terlalu mahal) karena biasanya itu:
        //    → listing Aksesoris (Rp 50rb keyboard di halaman Mini PC)
        //    → listing Paket Bundling Upgrade (Rp 50jt CPU + monitor)
        //    → iklan / listing typo.
        //    HANYA jika jumlah sample >= 3.
        // ══════════════════════════════════════════════════════════
        sort($prices, SORT_NUMERIC);
        if (count($prices) >= 3) {
            $values = array_values($prices);
            $q1Idx = (int) floor((count($values) - 1) * 0.25);
            $q3Idx = (int) floor((count($values) - 1) * 0.75);
            $q1    = $values[$q1Idx];
            $q3    = $values[$q3Idx];
            $iqr   = $q3 - $q1;
            $lower = $q1 - 1.5 * $iqr;
            $upper = $q3 + 1.5 * $iqr;
            $filteredPrices = [];
            $filteredSources = [];
            foreach ($prices as $idx => $p) {
                if ($p >= $lower && $p <= $upper) {
                    $filteredPrices[] = $p;
                    if (isset($sources[$idx])) {
                        $filteredSources[] = $sources[$idx];
                    }
                }
            }
            // Jika setelah dibuang outlier tersisa >= 2 sample, pakai.
            // Jika terlalu terbuang, fallback ke seluruh harga (aman).
            if (count($filteredPrices) >= 2) {
                $prices  = $filteredPrices;
                $sources = $filteredSources;
            }
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
    public function searchProductAlternatives(string $productCategory, array $requirements = [], ?array $targetDomains = null): array
    {
        $specsHint = implode(' ', array_slice(array_values($requirements), 0, 3));
        $query     = trim("rekomendasi {$productCategory} terbaik {$specsHint} harga B2B Indonesia 2025 2026");

        return $this->searchProducts($query, 8, $targetDomains);
    }

    /**
     * Temukan brand/merek terbaik untuk kategori produk, lalu cari harga per brand.
     *
     * Digunakan ketika user tidak menyebutkan brand spesifik dan sistem perlu
     * merekomendasikan pilihan merek beserta data harga dari web.
     *
     * @param  string $itemName   Nama/kategori produk (misal: "smart bulb RGB")
     * @param  string $specs      Spesifikasi (misal: "9-12 watt")
     * @param  int    $maxBrands  Jumlah brand maksimal yang dicari (default 4)
     * @return array              Array per brand: [brand, item_name, results, web_prices]
     */
    public function searchBrandComparison(string $itemName, string $specs = '', int $maxBrands = 4): array
    {
        // Step 1: Temukan brand terbaik dari web
        $brandQuery = "rekomendasi merk brand {$itemName} {$specs} terbaik Indonesia 2025 harga";
        $brandResults = $this->searchProducts($brandQuery, 10);

        // Ekstrak nama brand dari title hasil pencarian menggunakan pattern umum
        $knownBrands   = [];
        $brandPatterns = [
            'Philips', 'Xiaomi', 'Mi', 'Yeelight', 'TP-Link', 'KASA', 'Sengled',
            'Govee', 'Wyze', 'Tuya', 'Sonoff', 'IKEA', 'Panasonic', 'Osram',
            'Samsung', 'LG', 'Bardi', 'Smartlife', 'Ecolink', 'Lifx', 'Nanoleaf',
            'Hue', 'Wiz', 'Meross', 'Lepro', 'Innr', 'Bosch', 'ACPower',
            // Heavy equipment & generic brands
            'Komatsu', 'Caterpillar', 'CAT', 'Hitachi', 'Volvo', 'Sany', 'XCMG',
            'HP', 'Dell', 'Lenovo', 'Asus', 'Acer', 'Apple', 'Microsoft',
        ];

        foreach ($brandResults as $r) {
            $text = ($r['title'] ?? '') . ' ' . ($r['snippet'] ?? '');
            foreach ($brandPatterns as $brand) {
                if (stripos($text, $brand) !== false && !in_array($brand, $knownBrands)) {
                    $knownBrands[] = $brand;
                }
            }
            if (count($knownBrands) >= $maxBrands * 2) break;
        }

        // Jika tidak ada brand terdeteksi dari pattern, pakai top domain sebagai fallback
        if (empty($knownBrands)) {
            $knownBrands = array_slice(array_map(fn($r) => $r['source'] ?? '', $brandResults), 0, $maxBrands);
            $knownBrands = array_filter($knownBrands);
        }

        $knownBrands = array_unique(array_slice($knownBrands, 0, $maxBrands));

        if (empty($knownBrands)) {
            return [];
        }

        // Step 2: Cari harga & spesifikasi per brand
        $brandComparisons = [];
        foreach ($knownBrands as $brand) {
            // ══════════════════════════════════════════════════════════
            // 🔥 FIX 6b — Query PER BRAND LEBIH SPESIFIK:
            //    Jangan "harga Indonesia" saja; tapi sertakan "harga unit
            //    produk utama lengkap (bukan aksesoris/part)" agar Brave
            //    tidak mengembalikan listing keyboard/ssd satuannya ketika
            //    kita sebenarnya sedang cari satu unit laptop/mini pc merk X.
            // ══════════════════════════════════════════════════════════
            $query   = trim("{$brand} {$itemName} {$specs} harga unit produk utama lengkap"
                          . " (site:tokopedia.com OR site:blibli.com OR site:shopee.co.id OR site:bhinneka.com)");
            $results = $this->searchProducts($query, 6);

            if (empty($results)) continue;

            // Ekstrak harga dari hasil per brand + IQR outlier filter
            $prices = [];
            $priceSources = [];
            foreach ($results as $r) {
                $p = $this->extractPriceFromText(($r['title'] ?? '') . ' ' . ($r['snippet'] ?? ''));
                if ($p > 0) {
                    $prices[] = $p;
                    $priceSources[] = [
                        'title' => $r['title'],
                        'link'  => $r['link'],
                        'price' => $p,
                    ];
                }
            }

            // Outlier filter per brand (jika >= 3 sampel)
            if (count($prices) >= 3) {
                sort($prices, SORT_NUMERIC);
                $vals   = array_values($prices);
                $q1     = $vals[(int) floor((count($vals) - 1) * 0.25)];
                $q3     = $vals[(int) floor((count($vals) - 1) * 0.75)];
                $iqr    = $q3 - $q1;
                $lower  = $q1 - 1.5 * $iqr;
                $upper  = $q3 + 1.5 * $iqr;
                $filtPrices = [];
                $filtSrc    = [];
                foreach ($prices as $i => $p) {
                    if ($p >= $lower && $p <= $upper) {
                        $filtPrices[] = $p;
                        if (isset($priceSources[$i])) $filtSrc[] = $priceSources[$i];
                    }
                }
                if (count($filtPrices) >= 2) {
                    $prices       = $filtPrices;
                    $priceSources = $filtSrc;
                }
            }

            $avgPrice = !empty($prices) ? round(array_sum($prices) / count($prices)) : null;
            $thumbnail = null;
            foreach ($results as $r) {
                if (!empty($r['thumbnail'])) { $thumbnail = $r['thumbnail']; break; }
            }

            $brandComparisons[] = [
                'brand'      => $brand,
                'item_name'  => "{$brand} {$itemName}",
                'results'    => array_slice($results, 0, 3),
                'web_prices' => [
                    'avg_price' => $avgPrice,
                    'min_price' => !empty($prices) ? min($prices) : null,
                    'max_price' => !empty($prices) ? max($prices) : null,
                    'sources'   => $priceSources,
                ],
                'thumbnail'  => $thumbnail,
            ];
        }

        return $brandComparisons;
    }


    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Tambahkan filter site: ke query agar Brave memprioritaskan domain tertentu.
     *
     * Contoh hasil: "Komatsu PC200 harga (site:unitedtractors.com OR site:tokopedia.com OR ...)"
     *
     * @param  string     $query         Query asli
     * @param  array|null $targetDomains Domain list (null = pakai TARGET_DOMAINS default)
     * @return string                    Query yang sudah diperkaya
     */
    private function buildQueryWithDomains(string $query, ?array $targetDomains): string
    {
        $domains = $targetDomains ?? self::TARGET_DOMAINS;

        if (empty($domains)) {
            return $query;
        }

        $siteFilter = implode(' OR ', array_map(
            fn(string $d) => 'site:' . ltrim($d, '.'),
            $domains
        ));

        return trim("{$query} ({$siteFilter})");
    }

    /**
     * Panggil Brave Search API.
     */
    private function fetchSearch(string $query, int $limit): array
    {
        try {
            $response = Http::withHeaders([
                'Accept'               => 'application/json',
                'X-Subscription-Token' => $this->apiKey,
            ])
            ->timeout($this->timeout)
            ->retry(2, 1000)
            ->get(self::ENDPOINT, [
                'q'       => $query,
                'count'   => min(max($limit, 1), 20),
                'country' => 'id',
            ]);

            if (!$response->successful()) {
                Log::warning('BraveSearchService: API request failed', [
                    'status' => $response->status(),
                    'query'  => $query,
                    'body'   => $response->body(),
                ]);
                return [];
            }

            $data = $response->json();
            $webResults = $data['web']['results'] ?? [];

            return array_map(function ($item) {
                // Strip HTML tags from description
                $snippet = strip_tags($item['description'] ?? '');
                $extraSnippets = $item['extra_snippets'] ?? [];
                if (!empty($extraSnippets)) {
                    $snippet .= ' ' . implode(' ', array_map('strip_tags', $extraSnippets));
                }

                $title = strip_tags($item['title'] ?? '');
                $link = $item['url'] ?? '';

                return [
                    'title'     => $title,
                    'link'      => $link,
                    'snippet'   => trim($snippet),
                    'source'    => parse_url($link, PHP_URL_HOST) ?: '',
                    'thumbnail' => $item['thumbnail']['src'] ?? null,
                    'price'     => $this->extractPriceFromText($title . ' ' . $snippet),
                ];
            }, $webResults);

        } catch (\Throwable $e) {
            Log::error('BraveSearchService: fetchSearch exception', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Ekstrak harga rupiah dari teks (snippet / judul halaman web).
     * Mendukung: Rp 1.234.567 | IDR 1,234,567 | Rp 1,5 miliar | Rp 500 juta
     *            Rp 139rb | Rp 139K | 139,000 | 139.000
     */
    private function extractPriceFromText(string $text): float
    {
        // 1. Format kata: "Rp 1,5 miliar / juta / jt"
        if (preg_match('/(?:rp\.?|idr\.?)\s*([\d.,]+)\s*(miliar|milyar|jt|juta|mio|b)/i', $text, $m)) {
            $num  = (float) str_replace(',', '.', preg_replace('/[.,](?=\d{3})/', '', $m[1]));
            $unit = strtolower($m[2]);
            if (str_starts_with($unit, 'm') && !str_starts_with($unit, 'mi')) {
                return $num * 1_000_000_000;
            }
            return $num * 1_000_000;
        }

        // 2. Format "Rp 139rb" / "Rp 139K" / "Rp 139k"
        if (preg_match('/(?:rp\.?|idr\.?)\s*([\d.,]+)\s*(?:rb|ribu|k\b)/i', $text, $m)) {
            $num = (float) str_replace(['.', ','], ['', '.'], $m[1]);
            $val = $num * 1000;
            if ($val >= 5_000 && $val <= 50_000_000_000) return $val;
        }

        // 3. Format standar: "Rp 139.000" atau "IDR 139,000"
        if (preg_match('/(?:rp\.?|idr\.?)\s*([\d.,]+)/i', $text, $m)) {
            $raw = $m[1];
            // Deteksi pemisah ribuan: titik (ID) atau koma (EN)
            // Contoh: 139.000 → 139000 | 1,234,567 → 1234567
            $cleaned = preg_replace('/[.,](?=\d{3}(?:[.,]|$))/', '', $raw);
            $cleaned = str_replace(',', '.', $cleaned);
            $val     = (float) $cleaned;
            if ($val >= 5_000 && $val <= 50_000_000_000) {
                return $val;
            }
        }

        // 4. Angka besar standalone: "15.000.000" atau "1,234,567"
        if (preg_match('/\b([\d]{1,3}(?:[.,]\d{3}){1,})(?:[.,]\d{1,2})?\b/', $text, $m)) {
            $num = preg_replace('/[.,](?=\d{3})/', '', $m[1]);
            $val = (float) str_replace(',', '.', $num);
            if ($val >= 10_000 && $val <= 50_000_000_000) {
                return $val;
            }
        }

        return 0;
    }
}

