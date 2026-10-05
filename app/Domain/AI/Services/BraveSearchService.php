<?php

namespace App\Domain\AI\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * BraveSearchService
 *
 * Wrapper Brave Search API untuk riset produk & harga pasar.
 *
 * Prinsip anti-halusinasi di file ini:
 *  - Harga hanya diambil dari angka berawalan Rp/IDR, bukan angka lepas.
 *  - Hasil pencarian difilter relevansinya SEBELUM harga dihitung (buang aksesoris, bekas, dll).
 *  - Statistik harga memakai MEDIAN dan wajib minimal 3 sampel; jika kurang => null (bukan tebakan).
 *  - Harga dan link sumber selalu dipasangkan dalam satu record sehingga tidak bisa tertukar.
 *  - Hasil kosong / error tidak di-cache.
 *  - Deteksi merek memakai pencocokan kata utuh dan wajib diverifikasi oleh pencarian per merek.
 *
 * Konfigurasi:
 *   BRAVE_SEARCH_API_KEY  - API key Brave Search
 *   config('ai.known_brands') - (opsional) daftar tambahan merek untuk deteksi
 */
class BraveSearchService
{
    private const ENDPOINT = 'https://api.search.brave.com/res/v1/web/search';
    private const CACHE_TTL = 3600; // 1 jam

    private const MIN_PRICE = 5_000;
    private const MAX_PRICE = 50_000_000_000;
    private const MIN_PRICE_SAMPLES = 3;

    /** Domain default: marketplace umum. Kategori khusus (alat berat) harus diminta eksplisit. */
    public const MARKETPLACE_DOMAINS = [
        'tokopedia.com',
        'shopee.co.id',
        'blibli.com',
        'bhinneka.com',
        'indotrading.com',
    ];

    public const HEAVY_EQUIPMENT_DOMAINS = [
        'unitedtractors.com',
        'sanyindonesia.co.id',
    ];

    /** Merek yang dikenali. Tidak ada merek 1-2 huruf yang ambigu (mis. "Mi"). */
    private const KNOWN_BRANDS = [
        'Philips',
        'Xiaomi',
        'Yeelight',
        'TP-Link',
        'Sengled',
        'Govee',
        'Tuya',
        'Sonoff',
        'IKEA',
        'Panasonic',
        'Osram',
        'Samsung',
        'LG',
        'Bardi',
        'Smartlife',
        'Lifx',
        'Nanoleaf',
        'Meross',
        'Lepro',
        'Bosch',
        'Komatsu',
        'Caterpillar',
        'Hitachi',
        'Volvo',
        'Sany',
        'XCMG',
        'HP',
        'Dell',
        'Lenovo',
        'Asus',
        'Acer',
        'Apple',
        'Microsoft',
        'Minisforum',
        'Intel',
        'Logitech',
        'Epson',
        'Canon',
        'Brother',
    ];

    /** Kata pada judul yang menandakan listing aksesoris / bukan unit utama. */
    private const ACCESSORY_WORDS = [
        'casing',
        'case',
        'cover',
        'charger',
        'kabel',
        'sparepart',
        'spare part',
        'bekas',
        'second',
        'replika',
        'sewa',
        'rental',
        'stiker',
        'sticker',
        'skin',
        'pelindung',
        'tempered',
        'adaptor',
        'adapter',
    ];

    private const STOPWORDS = [
        'dan',
        'yang',
        'untuk',
        'dengan',
        'harga',
        'murah',
        'baru',
        'original',
        'resmi',
        'unit',
        'set',
        'pcs',
        'the',
        'for',
        'with',
    ];

    /** Penanda konteks BUKAN harga jual (cicilan, ongkir, per kemasan, dll). */
    private const NON_PRICE_MARKERS = '/cicil|angsur|per\s*bulan|\/\s*(?:bln|bulan)|ongkir|ongkos\s*kirim|uang\s*muka|\bdp\b|per\s*(?:lusin|dus|box|karton|pack)\b|isi\s*\d+/i';

    private string $apiKey;
    private int $timeout;
    private array $knownBrands;
    private bool $lastSearchFailed = false;

    public function __construct()
    {
        $this->apiKey = (string) config('ai.brave_search_api_key', env('BRAVE_SEARCH_API_KEY', ''));
        $this->timeout = (int) config('ai.timeout', 30);

        $extra = (array) config('ai.known_brands', []);
        $this->knownBrands = array_values(array_unique(array_merge(self::KNOWN_BRANDS, $extra)));
    }

    public function isEnabled(): bool
    {
        return !empty($this->apiKey);
    }

    /**
     * True jika pemanggilan API terakhir GAGAL (bukan sekadar tidak ada hasil).
     */
    public function lastSearchFailed(): bool
    {
        return $this->lastSearchFailed;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Public API
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Cari produk di internet.
     *
     * @param  array|null $targetDomains null = MARKETPLACE_DOMAINS, [] = tanpa filter domain
     * @return array      Daftar: title, link, snippet, source, thumbnail, price
     */
    public function searchProducts(string $query, int $limit = 5, ?array $targetDomains = null): array
    {
        if (!$this->isEnabled()) {
            Log::debug('BraveSearchService: API key not configured, skipping search.');
            return [];
        }

        $enrichedQuery = $this->buildQueryWithDomains($query, $targetDomains);
        $cacheKey = 'bsearch_' . md5($enrichedQuery . '_' . $limit);

        try {
            $cached = Cache::get($cacheKey);
        } catch (\Throwable $e) {
            $cached = null;
            Log::warning('BraveSearchService: cache read failed, continuing without cache', [
                'error' => $e->getMessage(),
            ]);
        }

        if (is_array($cached) && !empty($cached)) {
            $this->lastSearchFailed = false;
            return $cached;
        }

        $results = $this->fetchSearch($enrichedQuery, $limit);

        if ($results === null) {
            $this->lastSearchFailed = true;
            return [];
        }

        $this->lastSearchFailed = false;

        // Jangan cache hasil kosong agar error sementara tidak "menempel" 1 jam.
        if (!empty($results)) {
            try {
                Cache::put($cacheKey, $results, self::CACHE_TTL);
            } catch (\Throwable $e) {
                Log::warning('BraveSearchService: cache write failed, returning live results', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $results;
    }

    /**
     * Cari harga pasar satu item.
     *
     * avg_price adalah MEDIAN dari harga yang lolos filter relevansi & outlier.
     * Jika sampel < 3, seluruh angka statistik bernilai null.
     *
     * @return array [min_price, max_price, avg_price, sample_count, sources, raw_results]
     */
    public function searchMarketPrice(string $itemName, string $brand = '', string $specs = '', ?array $targetDomains = null): array
    {
        $result = $this->emptyPriceResult();
        $itemName = trim($itemName);
        if ($itemName === '') {
            return $result;
        }

        $query = trim(implode(' ', array_filter([trim($brand), $itemName, trim($specs)])) . ' harga');
        $results = $this->searchProducts($query, 20, $targetDomains);

        // 1) Buang hasil tidak relevan / aksesoris SEBELUM menghitung harga.
        $relevant = $this->filterRelevant($results, $itemName, $brand);
        $samples = $this->collectPriceSamples($relevant);

        // A broad retry helps when the marketplace-restricted query has too few priced listings.
        if (count($samples) < self::MIN_PRICE_SAMPLES || $this->summarizePrices($samples) === null) {
            $broadQuery = trim(implode(' ', array_filter([
                trim($brand),
                $itemName,
                trim($specs),
                'harga Indonesia baru',
            ])));
            $retryDomains = $targetDomains === null ? [] : $targetDomains;
            $retryResults = $this->filterRelevant(
                $this->searchProducts($broadQuery, 20, $retryDomains),
                $itemName,
                $brand
            );
            $relevant = $this->mergeUniqueSearchResults($relevant, $retryResults);
            $samples = $this->collectPriceSamples($relevant);
        }

        $result['raw_results'] = $relevant;
        $result['sample_count'] = count($samples);
        $result['sources'] = $samples;

        // 3) Statistik hanya jika cukup sampel.
        $summary = $this->summarizePrices($samples);
        if ($summary === null) {
            return $result;
        }

        return array_merge($result, [
            'min_price' => $summary['min'],
            'max_price' => $summary['max'],
            'avg_price' => $summary['median'],
            'sample_count' => $summary['count'],
            'sources' => $summary['sources'],
        ]);
    }

    private function mergeUniqueSearchResults(array $primary, array $additional): array
    {
        $merged = [];
        foreach (array_merge($primary, $additional) as $result) {
            $link = trim((string) ($result['link'] ?? ''));
            $identity = $link !== ''
                ? $link
                : mb_strtolower(trim((string) ($result['title'] ?? '') . ' ' . ($result['snippet'] ?? '')));
            if ($identity === '' || isset($merged[$identity])) {
                continue;
            }
            $merged[$identity] = $result;
        }

        return array_values($merged);
    }

    public function searchProductSpecs(array $items): array
    {
        $results = [];

        foreach ($items as $item) {
            $name = $item['name'] ?? '';
            $brand = $item['brand'] ?? '';
            $category = $item['category'] ?? '';

            if (empty($name)) {
                continue;
            }

            $query = trim("{$brand} {$name} {$category} spesifikasi teknis Indonesia");
            $results[strtolower(trim($name))] = [
                'query' => $query,
                'results' => $this->filterRelevant($this->searchProducts($query, 5, []), $name, $brand),
            ];
        }

        return $results;
    }

    public function searchProductAlternatives(string $productCategory, array $requirements = [], ?array $targetDomains = null): array
    {
        $specsHint = implode(' ', array_slice(array_values($requirements), 0, 3));
        $query = trim("rekomendasi {$productCategory} {$specsHint} Indonesia");

        return $this->filterRelevant($this->searchProducts($query, 8, $targetDomains), $productCategory);
    }

    /**
     * Deteksi merek yang muncul di web untuk satu kategori produk, lalu VERIFIKASI
     * setiap merek dengan pencarian tersendiri (minimal 2 listing relevan berisi merek tsb).
     *
     * Hasil adalah "merek yang terdeteksi di web", bukan rekomendasi resmi.
     */
    public function searchBrandComparison(string $itemName, string $specs = '', int $maxBrands = 4): array
    {
        $itemName = trim($itemName);
        if ($itemName === '') {
            return [];
        }

        // Step 1: temukan merek dari hasil pencarian yang relevan.
        $discovery = $this->searchProducts("merk {$itemName} {$specs} populer Indonesia", 20);
        $discovery = $this->filterRelevant($discovery, $itemName);
        if (empty($discovery)) {
            return [];
        }

        $counts = [];
        foreach ($discovery as $r) {
            $text = ($r['title'] ?? '') . ' ' . ($r['snippet'] ?? '');
            foreach ($this->knownBrands as $brand) {
                if ($this->textMentionsBrand($text, $brand)) {
                    $counts[$brand] = ($counts[$brand] ?? 0) + 1;
                }
            }
        }

        if (empty($counts)) {
            return [];
        }

        arsort($counts);
        $candidates = array_slice(array_keys($counts), 0, max(1, $maxBrands) * 2);

        // Step 2: verifikasi per merek.
        $comparisons = [];
        foreach ($candidates as $brand) {
            if (count($comparisons) >= $maxBrands) {
                break;
            }

            $price = $this->searchMarketPrice($itemName, $brand, $specs);
            $results = $price['raw_results'];

            if (count($results) < 2) {
                continue; // merek tidak terbukti punya listing produk yang relevan
            }

            $thumbnail = null;
            foreach ($results as $r) {
                if (!empty($r['thumbnail'])) {
                    $thumbnail = $r['thumbnail'];
                    break;
                }
            }

            $comparisons[] = [
                'brand' => $brand,
                'item_name' => "{$brand} {$itemName}",
                'results' => array_slice($results, 0, 10),
                'web_prices' => [
                    'avg_price' => $price['avg_price'],
                    'min_price' => $price['min_price'],
                    'max_price' => $price['max_price'],
                    'sample_count' => $price['sample_count'],
                    'sources' => $price['sources'],
                ],
                'thumbnail' => $thumbnail,
                'detected_from_web' => true,
            ];
        }

        return $comparisons;
    }

    /**
     * Filter hasil pencarian: relevan terhadap nama item, memuat merek (jika ada),
     * dan bukan listing aksesoris.
     */
    public function filterRelevant(array $results, string $itemName, string $brand = ''): array
    {
        return array_values(array_filter(
            $results,
            fn($r) => $this->isRelevantResult($r, $itemName, $brand)
        ));
    }

    public function isRelevantResult(array $result, string $itemName, string $brand = ''): bool
    {
        $title = mb_strtolower((string) ($result['title'] ?? ''));
        $text = $title . ' ' . mb_strtolower((string) ($result['snippet'] ?? ''));

        $tokens = $this->tokenize($itemName);
        if (empty($tokens)) {
            return false;
        }

        $textTokens = $this->tokenize($text);
        $hits = count(array_intersect($tokens, $textTokens));
        if ($hits / count($tokens) < 0.6) {
            return false;
        }

        $brand = mb_strtolower(trim($brand));
        if ($brand !== '' && !str_contains($text, $brand)) {
            return false;
        }

        $itemLower = mb_strtolower($itemName);
        foreach (self::ACCESSORY_WORDS as $word) {
            if (str_contains($itemLower, $word)) {
                continue; // user memang mencari barang ini
            }
            if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($word, '/') . '(?![\p{L}\p{N}])/u', $title)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Ekstrak harga rupiah dari teks. Hanya angka berawalan Rp/IDR yang dianggap harga.
     * Mengembalikan 0 jika tidak ada harga yang meyakinkan.
     */
    public function extractPriceFromText(string $text): float
    {
        if ($text === '') {
            return 0;
        }

        $pattern = '/(?<![\p{L}])(?:rp\.?|idr)\s*(\d+(?:[.,]\d+)*)(?:\s*(miliar|milyar|juta|jt|ribu|rb|k)(?![\p{L}]))?/iu';
        if (!preg_match_all($pattern, $text, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return 0;
        }

        foreach ($all as $m) {
            // Lewati angka yang konteksnya cicilan / ongkir / per kemasan.
            $start = max(0, $m[0][1] - 30);
            $window = substr($text, $start, strlen($m[0][0]) + 60);
            if (preg_match(self::NON_PRICE_MARKERS, $window)) {
                continue;
            }

            $value = $this->parseAmount($m[1][0], mb_strtolower($m[2][0] ?? ''));
            if ($value !== null) {
                return $value;
            }
        }

        return 0;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function emptyPriceResult(): array
    {
        return [
            'min_price' => null,
            'max_price' => null,
            'avg_price' => null,
            'sample_count' => 0,
            'sources' => [],
            'raw_results' => [],
        ];
    }

    /**
     * Satu record per sampel: harga dan link tidak mungkin tertukar.
     */
    private function collectPriceSamples(array $results): array
    {
        $samples = [];
        foreach ($results as $r) {
            $price = (float) ($r['price'] ?? 0);
            if ($price <= 0) {
                $price = $this->extractPriceFromText(($r['title'] ?? '') . ' ' . ($r['snippet'] ?? ''));
            }
            if ($price <= 0) {
                continue;
            }

            $samples[] = [
                'title' => $r['title'] ?? '',
                'link' => $r['link'] ?? '',
                'source' => $r['source'] ?? '',
                'price' => $price,
            ];
        }

        usort($samples, fn($a, $b) => $a['price'] <=> $b['price']);

        return $samples;
    }

    /**
     * Ringkasan harga berbasis MEDIAN. Null jika sampel kurang dari MIN_PRICE_SAMPLES
     * (sebelum maupun sesudah pembuangan outlier IQR).
     */
    private function summarizePrices(array $samples): ?array
    {
        if (count($samples) < self::MIN_PRICE_SAMPLES) {
            return null;
        }

        usort($samples, fn($a, $b) => $a['price'] <=> $b['price']);
        $prices = array_column($samples, 'price');
        $n = count($prices);

        $q1 = $prices[(int) floor(($n - 1) * 0.25)];
        $q3 = $prices[(int) floor(($n - 1) * 0.75)];
        $iqr = $q3 - $q1;
        $lower = $q1 - 1.5 * $iqr;
        $upper = $q3 + 1.5 * $iqr;

        $kept = array_values(array_filter(
            $samples,
            fn($s) => $s['price'] >= $lower && $s['price'] <= $upper
        ));

        if (count($kept) < self::MIN_PRICE_SAMPLES) {
            return null; // data terlalu berantakan untuk dipercaya
        }

        $p = array_column($kept, 'price');
        $m = count($p);
        $median = $m % 2 === 1
            ? $p[intdiv($m, 2)]
            : ($p[$m / 2 - 1] + $p[$m / 2]) / 2;

        return [
            'min' => min($p),
            'max' => max($p),
            'median' => round($median),
            'count' => $m,
            'sources' => $kept,
        ];
    }

    /**
     * Parse angka rupiah. Mengembalikan null jika ambigu atau di luar batas wajar.
     */
    private function parseAmount(string $raw, string $unit): ?float
    {
        $multiplier = match ($unit) {
            'miliar', 'milyar' => 1_000_000_000,
            'juta', 'jt' => 1_000_000,
            'ribu', 'rb', 'k' => 1_000,
            '' => 1,
            default => null,
        };

        if ($multiplier === null) {
            return null;
        }

        if ($multiplier > 1) {
            if (preg_match('/^\d+$/', $raw)) {
                $num = (float) $raw;
            } elseif (preg_match('/^\d+[.,]\d{1,2}$/', $raw)) {
                $num = (float) str_replace(',', '.', $raw);
            } else {
                return null;
            }
        } else {
            if (preg_match('/^\d+$/', $raw)) {
                $num = (float) $raw;
            } elseif (preg_match('/^\d{1,3}(?:[.,]\d{3})+$/', $raw)) {
                $num = (float) preg_replace('/[.,]/', '', $raw);
            } elseif (preg_match('/^(\d{1,3}(?:\.\d{3})+),\d{1,2}$/', $raw, $mm)) {
                $num = (float) str_replace('.', '', $mm[1]);
            } elseif (preg_match('/^(\d{1,3}(?:,\d{3})+)\.\d{1,2}$/', $raw, $mm)) {
                $num = (float) str_replace(',', '', $mm[1]);
            } else {
                return null;
            }
        }

        $value = $num * $multiplier;

        return ($value >= self::MIN_PRICE && $value <= self::MAX_PRICE) ? $value : null;
    }

    private function tokenize(string $text): array
    {
        $text = preg_replace('/smart[\s-]*bulb/iu', 'smart bulb', $text);
        $text = mb_strtolower(preg_replace('/[^\p{L}\p{N}\s\-]/u', ' ', $text));
        $tokens = preg_split('/\s+/u', trim($text)) ?: [];

        return array_values(array_unique(array_filter(
            $tokens,
            fn($t) => mb_strlen($t) >= 2 && !in_array($t, self::STOPWORDS, true)
        )));
    }

    private function textMentionsBrand(string $text, string $brand): bool
    {
        $quoted = preg_quote($brand, '/');
        $flags = mb_strlen($brand) <= 3 ? 'u' : 'iu'; // merek pendek (LG, HP) wajib huruf kapital persis

        return (bool) preg_match(
            '/(?<![\p{L}\p{N}])' . $quoted . '(?![\p{L}\p{N}])/' . $flags,
            $text
        );
    }

    /**
     * Tambahkan filter site: sekali saja. [] = tanpa filter domain.
     */
    private function buildQueryWithDomains(string $query, ?array $targetDomains): string
    {
        $domains = $targetDomains ?? self::MARKETPLACE_DOMAINS;

        if (empty($domains)) {
            return trim($query);
        }

        $siteFilter = implode(' OR ', array_map(
            fn(string $d) => 'site:' . ltrim($d, '.'),
            $domains
        ));

        return trim("{$query} ({$siteFilter})");
    }

    /**
     * Panggil Brave Search API.
     *
     * @return array|null  null = request GAGAL; [] = berhasil tapi tidak ada hasil
     */
    private function fetchSearch(string $query, int $limit): ?array
    {
        try {
            $response = Http::withHeaders([
                'Accept' => 'application/json',
                'X-Subscription-Token' => $this->apiKey,
            ])
                ->timeout($this->timeout)
                ->retry(2, 1000)
                ->get(self::ENDPOINT, [
                    'q' => $query,
                    'count' => min(max($limit, 1), 20),
                    'country' => 'id',
                ]);

            if (!$response->successful()) {
                Log::warning('BraveSearchService: API request failed', [
                    'status' => $response->status(),
                    'query' => $query,
                    'body' => $response->body(),
                ]);
                return null;
            }

            $webResults = $response->json('web.results') ?? [];

            return array_map(function ($item) {
                $snippet = strip_tags($item['description'] ?? '');
                foreach (($item['extra_snippets'] ?? []) as $extra) {
                    $snippet .= ' ' . strip_tags($extra);
                }

                $title = strip_tags($item['title'] ?? '');
                $link = $item['url'] ?? '';

                return [
                    'title' => $title,
                    'link' => $link,
                    'snippet' => trim($snippet),
                    'source' => parse_url($link, PHP_URL_HOST) ?: '',
                    'thumbnail' => $item['thumbnail']['src'] ?? null,
                    'price' => $this->extractPriceFromText($title . ' ' . $snippet),
                ];
            }, $webResults);
        } catch (\Throwable $e) {
            Log::error('BraveSearchService: fetchSearch exception', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}