<?php

namespace App\Domain\AI\Services;

use App\Domain\Catalogue\Models\Catalogue;
use App\Domain\Company\Models\Company;
use App\Domain\Order\Models\HistoricalPoItem;
use App\Domain\Rfq\Actions\CreateRfqAction;
use App\Domain\Rfq\Models\Rfq;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * AgenticProcurementService
 *
 * Orkestrasi Agentic AI Procurement:
 *  1. Analisis kebutuhan
 *  2. Referensi harga historis (PO & proposal pemenang)
 *  3. Riset web (Brave Search)
 *  4. Pencarian katalog + penilaian AI
 *  5. Komparasi produk
 *  6. Penyusunan PR + penetapan harga
 *  7. (opsional) pembuatan PR/RFQ
 *
 * Prinsip anti-halusinasi:
 *  - Item PR disusun SERVER dari kebutuhan buyer + katalog tervalidasi, bukan dari output AI.
 *  - Harga hanya dari sumber yang bisa ditelusuri: histori PO -> web (min. 3 sampel) -> anggaran
 *    per-item yang benar-benar disebut buyer. Selain itu rfq_required. TIDAK ADA pembagian budget rata.
 *  - Pencocokan produk <-> data web/histori ketat (kategori, merek, token), tanpa fuzzy lemah.
 *  - Tidak ada nilai default yang mengklaim fakta (kode item, "Universal", "Sesuai spesifikasi", dll).
 */
class AgenticProcurementService
{
    /**
     * Keluarga produk yang TIDAK boleh saling dicocokkan.
     * Keluarga utama sebuah teks = keluarga yang kata kuncinya muncul PALING AWAL di teks itu.
     */
    private const FAMILIES = [
        'FULL_PC' => [
            'mini pc',
            'mini-pc',
            'minipc',
            'pc desktop',
            'pc core',
            'pc fullset',
            'komputer full set',
            'workstation',
            'server tower',
            'all in one pc',
            'nuc '
        ],
        'LAPTOP' => ['laptop', 'notebook', 'ultrabook', 'ultrathin', 'thinkpad', 'macbook', 'chromebook'],
        'STORAGE_SSD' => ['ssd nvme', 'ssd sata', 'ssd 2.5', 'nvme gen', 'm.2 ssd', 'solid state drive'],
        'STORAGE_HDD' => ['harddisk', 'hard disk', 'hdd sata', 'internal hdd'],
        'MEMORY_RAM' => ['memory ram', 'ram ddr', 'ddr4 sodimm', 'ddr5 sodimm', 'memory module'],
        'MONITOR' => ['monitor'],
        'PRINTER' => ['printer laser', 'printer inkjet', 'multifungsi printer', 'dot matrix printer', 'printer'],
        'SMARTPHONE' => ['smartphone', 'handphone', 'hp android', 'iphone', 'ponsel'],
        'TABLET' => ['ipad', 'tablet android', 'tablet windows', 'surface pro'],
        'NETWORK' => ['wireless router', 'switch gigabit', 'access point', 'wifi 6 router'],
        'UPS' => ['ups 1500va', 'ups 1000va', 'uninterruptible power supply'],
        'PROJECTOR' => ['projector', 'proyektor'],
        'ACCESSORY' => ['keyboard', 'mouse', 'mousepad', 'headset gaming', 'earphone'],
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

    /** Skor minimum agar sebuah entri web dianggap cocok dengan item. */
    private const MIN_WEB_MATCH_SCORE = 70;

    public function __construct(
        private readonly OpenAiService $openAi,
        private readonly BraveSearchService $webSearch,
        private readonly CreateRfqAction $createRfqAction
    ) {
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Workflow utama
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param string $query   Kebutuhan pengadaan (bahasa natural)
     * @param array  $options [company_id, user_id, auto_create_pr, catalogue_ids]
     */
    public function runFullWorkflow(string $query, array $options = []): array
    {
        $steps = [];

        // Step 1: Analisis kebutuhan
        $intent = $this->openAi->extractSearchIntent($query);
        $steps[] = [
            'step' => 'intent_analysis',
            'title' => 'Analisis Kebutuhan & Spesifikasi',
            'status' => 'completed',
            'summary' => $intent['ai_summary'] ?? 'Analisis kebutuhan selesai.',
            'target_items' => $intent['target_items'] ?? [],
        ];

        // Step 2: Harga historis (sumber paling tepercaya)
        $historicalPrices = $this->queryHistoricalPrices($intent);
        $historicalCount = count($historicalPrices);
        $steps[] = [
            'step' => 'historical_price_lookup',
            'title' => 'Referensi Harga Historis (PO & Tender)',
            'status' => 'completed',
            'total_found' => $historicalCount,
            'summary' => $historicalCount > 0
                ? "Ditemukan {$historicalCount} referensi harga dari transaksi PO & penawaran vendor sebelumnya."
                : 'Tidak ada riwayat transaksi yang cocok. Harga akan ditentukan melalui RFQ bila tidak ada referensi lain.',
            'sources' => array_values(array_map(fn($v) => [
                'name' => $v['item_name'],
                'avg_price' => $v['avg_price'],
                'last_price' => $v['last_price'],
                'source' => $v['source'],
                'samples' => $v['sample_count'],
            ], $historicalPrices)),
        ];

        // Step 3: Riset web
        [$webSearchResults, $brandComparisons, $webSearchSummary] = $this->runWebResearch($intent);
        $steps[] = [
            'step' => 'web_search',
            'title' => 'Brave Search - Harga & Spek dari Web',
            'status' => 'completed',
            'total_found' => count($webSearchResults),
            'brand_recommendations' => array_values(array_map(fn($bc) => [
                'brand' => $bc['brand'],
                'avg_price' => $bc['web_prices']['avg_price'] ?? null,
                'thumbnail' => $bc['thumbnail'] ?? null,
            ], $brandComparisons)),
            'summary' => $webSearchSummary,
            'sources' => collect($webSearchResults)
                ->flatMap(fn($d) => array_slice($d['results'] ?? [], 0, 2))
                ->map(fn($r) => ['title' => $r['title'] ?? '', 'link' => $r['link'] ?? '', 'price' => $r['price'] ?? 0])
                ->values()
                ->toArray(),
        ];

        // Step 4: Katalog
        $foundCatalogues = $this->discoverCatalogues($intent, $options);
        $steps[] = [
            'step' => 'catalogue_discovery',
            'title' => 'Pencarian Katalog Otomatis',
            'status' => 'completed',
            'total_found' => count($foundCatalogues),
            'summary' => count($foundCatalogues) > 0
                ? 'Ditemukan ' . count($foundCatalogues) . ' produk katalog yang cocok dengan kebutuhan.'
                : 'Tidak ada produk katalog yang cocok. Item PR mengikuti kebutuhan buyer dan harga ditentukan via RFQ.',
        ];

        // Step 5: Komparasi
        [$comparison, $comparisonStep] = $this->runComparison($query, $foundCatalogues, $brandComparisons, $webSearchResults);
        $steps[] = $comparisonStep;

        // Step 6: Susun PR
        $company = !empty($options['company_id']) ? Company::find($options['company_id']) : null;

        $items = $this->buildPrItems($intent, $foundCatalogues, $options);

        $context = [
            'company_name' => $company?->name,
            'address' => $company?->address,
            'department' => $intent['department'] ?? null,
            'urgency' => $intent['urgency'] ?? 'Normal',
        ];

        $prDraft = $this->openAi->generatePrDraft($query, $items, $context);

        $enrichedItems = $this->enrichPrItems($items, $webSearchResults, $historicalPrices);
        $prDraft['suggested_items'] = $enrichedItems;

        // Total HANYA dari item yang punya harga bersumber. Item rfq_required tidak dihitung.
        $priced = array_values(array_filter($enrichedItems, fn($i) => ($i['estimated_price'] ?? 0) > 0));
        $calculatedTotal = array_sum(array_map(fn($i) => $i['qty'] * $i['estimated_price'], $priced));

        $buyerBudget = (float) ($intent['estimated_total_budget_idr'] ?? 0);

        $prDraft['estimated_total_budget'] = $calculatedTotal;
        $prDraft['priced_item_count'] = count($priced);
        $prDraft['has_unpriced_items'] = count($priced) < count($enrichedItems);
        $prDraft['buyer_budget_limit'] = $buyerBudget > 0 ? $buyerBudget : null;

        $steps[] = [
            'step' => 'pr_formulation',
            'title' => 'Penyusunan Purchase Requisition (PR)',
            'status' => 'completed',
            'summary' => count($enrichedItems) > 0
                ? 'Draft PR disusun dari kebutuhan buyer. Harga hanya terisi bila ada sumber yang bisa ditelusuri.'
                : 'Tidak ada item yang dapat disusun dari permintaan ini.',
            'pr_title' => $prDraft['title'] ?? '',
            'price_sources_used' => collect($enrichedItems)->groupBy('price_status')->map->count()->toArray(),
        ];

        // Step 7: Eksekusi otomatis
        $createdRfq = null;
        if (!empty($options['auto_create_pr']) && $company && $company->type === 'buyer') {
            try {
                $createdRfq = $this->createPrFromDraft($company, $prDraft, $options['user_id'] ?? null);
                $steps[] = [
                    'step' => 'pr_creation',
                    'title' => 'Pembuatan PR ke Sistem Huntr',
                    'status' => 'completed',
                    'rfq_id' => $createdRfq->id,
                    'summary' => "PR berhasil dibuat dengan nomor ID: {$createdRfq->id}",
                ];
            } catch (\Exception $e) {
                Log::error('AgenticProcurement: Auto create PR failed', ['error' => $e->getMessage()]);
                $steps[] = [
                    'step' => 'pr_creation',
                    'title' => 'Pembuatan PR ke Sistem Huntr',
                    'status' => 'failed',
                    'summary' => 'Gagal menyimpan PR otomatis: ' . $e->getMessage(),
                ];
            }
        }

        return [
            'success' => true,
            'query' => $query,
            'intent' => $intent,
            'catalogues' => $foundCatalogues,
            'comparison' => $comparison,
            'pr_draft' => $prDraft,
            'web_search' => $webSearchResults,
            'created_rfq' => $createdRfq ? $createdRfq->load(['items.catalogue']) : null,
            'workflow_steps' => $steps,
        ];
    }

    /**
     * Chat interaktif. Tidak punya akses data harga/produk, sehingga dibatasi ketat.
     */
    public function chatWithAgent(array $messages, array $options = []): array
    {
        $systemInstruction = <<<'INSTRUCTION'
Kamu adalah "Huntr Agentic Procurement AI", asisten pengadaan barang & jasa B2B di platform Huntr.

Yang boleh kamu lakukan:
1. Membantu buyer merumuskan kebutuhan (spesifikasi, kuantitas, tujuan).
2. Menjelaskan konsep teknis dan standar pengadaan secara umum.
3. Membantu menyusun kerangka dokumen Purchase Requisition dari informasi yang diberikan buyer.

Batasan ketat:
- Kamu TIDAK memiliki akses ke harga, stok, vendor, atau katalog dalam percakapan ini. Jangan pernah menyebut angka harga, nama vendor, kode item, atau ketersediaan.
- Jika buyer menanyakan harga atau produk tertentu, jelaskan bahwa harga/produk diperoleh lewat fitur pencarian otomatis atau RFQ, dan sarankan menjalankannya.
- Jika tidak yakin, katakan tidak tahu.
- Bedakan jelas antara saran umum dan fakta.

Gunakan Bahasa Indonesia formal yang ramah dan terstruktur, dengan format markdown yang rapi.
INSTRUCTION;

        $reply = $this->openAi->chat(
            $messages,
            $systemInstruction,
            $options['company_id'] ?? null,
            'chatWithAgent'
        );

        $lastKey = array_key_last($messages);
        $lastUserMsg = mb_strtolower((string) ($lastKey !== null ? ($messages[$lastKey]['content'] ?? '') : ''));
        $hasIntent = false;
        foreach (['buat pr', 'bikin pr', 'cari', 'butuh', 'bandingkan'] as $needle) {
            if (str_contains($lastUserMsg, $needle)) {
                $hasIntent = true;
                break;
            }
        }

        return [
            'success' => true,
            'reply' => $reply,
            'has_procurement_intent' => $hasIntent,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Riset web & komparasi
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @return array [$webSearchResults, $brandComparisons, $summary]
     */
    private function runWebResearch(array $intent): array
    {
        $results = [];
        $brandComparisons = [];

        if (!$this->webSearch->isEnabled()) {
            return [$results, $brandComparisons, 'Brave Search tidak dikonfigurasi.'];
        }

        $targets = $intent['target_items'] ?? [];
        $keywords = $intent['keywords'] ?? [];

        foreach (array_slice($targets, 0, 5) as $target) {
            $name = trim((string) ($target['name'] ?? ''));
            $brand = trim((string) ($target['brand'] ?? ''));
            $spec = trim((string) ($target['spec_requirements'] ?? ''));
            if ($name === '') {
                continue;
            }

            $priceData = $this->webSearch->searchMarketPrice($name, $brand, $spec);
            if (!empty($priceData['raw_results'])) {
                $results[$this->normalizeKey($name)] = [
                    'item_name' => trim("{$brand} {$name}"),
                    'brand' => $brand,
                    'web_prices' => $priceData,
                    'results' => array_slice($priceData['raw_results'], 0, 20),
                ];
            }

            // Merek tidak disebut: deteksi merek yang ada di web (sekali saja).
            if ($brand === '' && empty($brandComparisons)) {
                $brandComparisons = $this->webSearch->searchBrandComparison($name, $spec, 4);
                $this->mergeBrandResults($results, $brandComparisons);
            }
        }

        // Tidak ada target item -> pencarian kategori dari keywords.
        if (empty($results) && !empty($keywords)) {
            $altQuery = implode(' ', array_slice($keywords, 0, 4));
            $alt = $this->webSearch->filterRelevant(
                $this->webSearch->searchProducts("{$altQuery} harga Indonesia", 8),
                $altQuery
            );
            if (!empty($alt)) {
                $results['__general__'] = ['item_name' => $altQuery, 'results' => $alt];
            }

            if (empty($brandComparisons)) {
                $brandComparisons = $this->webSearch->searchBrandComparison($altQuery, '', 4);
                $this->mergeBrandResults($results, $brandComparisons);
            }
        }

        $totalFound = count(array_filter($results, fn($v) => !empty($v['results'])));
        $brandCount = count($brandComparisons);

        if ($totalFound > 0) {
            $summary = "Brave Search menemukan {$totalFound} kelompok produk"
                . ($brandCount > 0 ? " dan {$brandCount} merek terdeteksi (belum terverifikasi)" : '')
                . '. Harga ditampilkan hanya jika tersedia minimal 3 sampel yang relevan.';
        } elseif ($this->webSearch->lastSearchFailed()) {
            $summary = 'Pencarian web gagal diakses (bukan berarti produk tidak ada). Coba ulangi.';
        } else {
            $summary = 'Brave Search aktif namun tidak menemukan hasil yang relevan.';
        }

        return [$results, $brandComparisons, $summary];
    }

    private function mergeBrandResults(array &$results, array $brandComparisons): void
    {
        foreach ($brandComparisons as $bc) {
            $key = $this->normalizeKey($bc['item_name']);
            if (!isset($results[$key])) {
                $results[$key] = [
                    'item_name' => $bc['item_name'],
                    'brand' => $bc['brand'],
                    'web_prices' => $bc['web_prices'],
                    'results' => $bc['results'],
                ];
            }
        }
    }

    /**
     * @return array [$comparison|null, $stepArray]
     */
    private function runComparison(string $query, array $catalogues, array $brandComparisons, array $webSearchResults): array
    {
        $matched = array_values(array_filter($catalogues, fn($c) => ($c['ai_match'] ?? false) === true));

        // Skenario A: >= 2 produk katalog yang dinyatakan cocok
        if (count($matched) >= 2) {
            $candidates = array_slice($matched, 0, 5);
            $comparison = $this->openAi->compareProducts($candidates, $query);

            if (!empty($comparison['comparison_matrix'])) {
                $byId = collect($candidates)->keyBy('id');

                $comparison['comparison_matrix'] = array_map(function ($row) use ($byId, $webSearchResults) {
                    $cat = $byId->get($row['catalogue_id']);
                    $entry = $cat
                        ? $this->bestWebEntry($cat['name'] ?? '', $cat['brand'] ?? '', $webSearchResults, false)
                        : null;

                    return array_merge($row, $this->webEnrichment(
                        $entry,
                        $cat['image_url'] ?? null
                    ), [
                        'vendor_name' => $row['vendor_name'] ?? ($cat['vendor'] ?? null),
                    ]);
                }, $comparison['comparison_matrix']);
            }

            return [
                $comparison,
                [
                    'step' => 'product_comparison',
                    'title' => 'Komparasi & Evaluasi Produk',
                    'status' => 'completed',
                    'summary' => $comparison['executive_summary'] ?? 'Evaluasi komparasi produk selesai.',
                    'winner_id' => $comparison['winner_id'] ?? null,
                ]
            ];
        }

        // Skenario B: merek terdeteksi di web (belum terverifikasi)
        if (count($brandComparisons) >= 2) {
            $synthetic = [];
            $byId = [];
            foreach ($brandComparisons as $bc) {
                $id = 'brave_' . preg_replace('/[^a-z0-9]+/', '_', strtolower($bc['brand']));
                $byId[$id] = $bc;

                $synthetic[] = [
                    'id' => $id,
                    'name' => $bc['item_name'],
                    'brand' => $bc['brand'],
                    'category' => null,
                    'specifications' => null,
                    'uom' => null,
                    'vendor' => null,
                    '_web_results' => $bc['results'],
                ];
            }

            $comparison = $this->openAi->compareProducts($synthetic, $query);

            if (!empty($comparison['comparison_matrix'])) {
                $comparison['comparison_matrix'] = array_map(function ($row) use ($byId) {
                    $bc = $byId[(string) $row['catalogue_id']] ?? null;

                    $entry = $bc ? [
                        'web_prices' => $bc['web_prices'],
                        'results' => $bc['results'],
                    ] : null;

                    return array_merge($row, $this->webEnrichment($entry, $bc['thumbnail'] ?? null), [
                        'vendor_name' => null, // merek BUKAN vendor
                    ]);
                }, $comparison['comparison_matrix']);
            }

            $comparison['source'] = 'brave_brand_comparison';
            $comparison['unverified'] = true;

            return [
                $comparison,
                [
                    'step' => 'product_comparison',
                    'title' => 'Komparasi Merek Terdeteksi (Brave Search, belum terverifikasi)',
                    'status' => 'completed',
                    'summary' => $comparison['executive_summary']
                        ?? ('Perbandingan ' . count($brandComparisons) . ' merek berdasarkan cuplikan web.'),
                    'winner_id' => $comparison['winner_id'] ?? null,
                    'source' => 'brave_brand_comparison',
                ]
            ];
        }

        $webListings = [];
        $seenLinks = [];
        foreach ($webSearchResults as $groupKey => $entry) {
            foreach (array_slice($entry['results'] ?? [], 0, 10) as $index => $result) {
                $title = trim((string) ($result['title'] ?? ''));
                $link = trim((string) ($result['link'] ?? ''));
                if ($title === '' && $link === '') {
                    continue;
                }

                $identity = $link !== '' ? $link : $groupKey . ':' . $index;
                if (isset($seenLinks[$identity])) {
                    continue;
                }
                $seenLinks[$identity] = true;

                $price = (float) ($result['price'] ?? 0);
                $webListings[] = [
                    'id' => 'brave_' . substr(sha1($identity), 0, 12),
                    'catalogue_id' => null,
                    'product_name' => $title !== '' ? $title : ($entry['item_name'] ?? 'Listing web'),
                    'vendor_name' => $result['source'] ?? (parse_url($link, PHP_URL_HOST) ?: null),
                    'key_specs' => mb_substr((string) ($result['snippet'] ?? ''), 0, 300) ?: null,
                    'pros' => [],
                    'cons' => [],
                    'best_for' => null,
                    'score' => null,
                    'value_rating' => null,
                    'web_listing_price' => $price > 0 ? $price : null,
                    'web_price_min' => null,
                    'web_price_max' => null,
                    'web_price_avg' => null,
                    'web_sources' => [[
                        'title' => $title,
                        'link' => $link,
                        'snippet' => mb_substr((string) ($result['snippet'] ?? ''), 0, 140),
                        'price' => $price > 0 ? $price : null,
                        'source' => $result['source'] ?? '',
                        'thumbnail' => $result['thumbnail'] ?? null,
                    ]],
                ];
            }
        }

        if (!empty($webListings)) {
            $comparison = [
                'comparison_matrix' => $webListings,
                'winner_id' => null,
                'winner_reason' => null,
                'executive_summary' => count($webListings) . ' listing ditemukan melalui Brave Search. Listing ditampilkan sebagai referensi web mentah, bukan rekomendasi atau perbandingan produk terverifikasi.',
                'spec_table' => [],
                'source' => 'brave_web_listings',
                'unverified' => true,
            ];

            return [
                $comparison,
                [
                    'step' => 'product_comparison',
                    'title' => 'Referensi Listing Web (Brave Search, belum diverifikasi)',
                    'status' => 'completed',
                    'summary' => $comparison['executive_summary'],
                    'source' => 'brave_web_listings',
                ],
            ];
        }

        return [
            null,
            [
                'step' => 'product_comparison',
                'title' => 'Evaluasi Produk',
                'status' => 'completed',
                'summary' => 'Kandidat produk belum cukup untuk dibandingkan.',
            ]
        ];
    }

    /**
     * Field tambahan dari satu entri web untuk baris matrix komparasi.
     * Harga bernilai null bila tidak ada statistik yang valid (bukan 0).
     */
    private function webEnrichment(?array $entry, ?string $fallbackThumbnail): array
    {
        $thumbnail = $fallbackThumbnail;
        $webSources = [];

        foreach (array_slice($entry['results'] ?? [], 0, 10) as $r) {
            $webSources[] = [
                'title' => $r['title'] ?? '',
                'link' => $r['link'] ?? '',
                'snippet' => mb_substr($r['snippet'] ?? '', 0, 140),
                'price' => $r['price'] ?? 0,
                'source' => $r['source'] ?? '',
                'thumbnail' => $r['thumbnail'] ?? null,
            ];
            if (!$thumbnail && !empty($r['thumbnail'])) {
                $thumbnail = $r['thumbnail'];
            }
        }

        $wp = $entry['web_prices'] ?? [];
        $avg = (float) ($wp['avg_price'] ?? 0);

        return [
            'thumbnail' => $thumbnail,
            'web_price_min' => $avg > 0 ? ($wp['min_price'] ?? null) : null,
            'web_price_max' => $avg > 0 ? ($wp['max_price'] ?? null) : null,
            'web_price_avg' => $avg > 0 ? $avg : null,
            'web_sources' => $webSources,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Harga historis
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Harga historis dari PO historis & proposal pemenang.
     *
     * @return array keyed by nama item (lowercase): item_name, specifications, avg_price, min_price,
     *               max_price, last_price, uom, sample_count, source, last_date
     */
    public function queryHistoricalPrices(array $intent): array
    {
        $terms = array_map(
            fn($t) => mb_strtolower(trim((string) $t)),
            array_merge(
                $intent['keywords'] ?? [],
                array_map(fn($t) => $t['name'] ?? '', $intent['target_items'] ?? [])
            )
        );
        $terms = array_values(array_unique(array_filter($terms, fn($t) => mb_strlen($t) >= 3)));

        if (empty($terms)) {
            return [];
        }

        $operator = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
        $like = fn(string $t) => '%' . addcslashes($t, '%_\\') . '%';
        $results = [];

        // Sumber 1: historical_po_items (hanya IDR; mata uang lain tidak boleh dirata-rata dengan IDR)
        try {
            $rows = HistoricalPoItem::query()
                ->whereNotNull('inventory_name')
                ->where('unit_price', '>', 0)
                ->where(fn($q) => $q->whereNull('currency')->orWhere('currency', 'IDR'))
                ->where(function ($q) use ($terms, $operator, $like) {
                    foreach ($terms as $term) {
                        $q->orWhere('inventory_name', $operator, $like($term))
                            ->orWhere('inventory_code', $operator, $like($term))
                            ->orWhere('specifications', $operator, $like($term));
                    }
                })
                ->select(['inventory_name', 'specifications', 'uom', 'unit_price', 'currency', 'order_date'])
                ->orderByDesc('order_date')
                ->limit(100)
                ->get();

            foreach ($rows->groupBy('inventory_name') as $itemName => $group) {
                $prices = $group->pluck('unit_price')->map(fn($p) => (float) $p)->filter(fn($p) => $p > 0);
                if ($prices->isEmpty()) {
                    continue;
                }

                $results[$this->normalizeKey($itemName)] = [
                    'item_name' => $itemName,
                    'specifications' => $group->first()?->specifications,
                    'avg_price' => round($prices->average()),
                    'min_price' => $prices->min(),
                    'max_price' => $prices->max(),
                    'last_price' => (float) ($group->first()?->unit_price ?? 0),
                    'uom' => $group->first()?->uom ?? 'unit',
                    'sample_count' => $prices->count(),
                    'source' => 'historical_po',
                    'last_date' => $group->first()?->order_date?->toDateString(),
                ];
            }
        } catch (\Throwable $e) {
            Log::warning('AgenticProcurementService: historical_po_items query failed', ['error' => $e->getMessage()]);
        }

        // Sumber 2: proposal pemenang
        try {
            $rows = DB::table('proposal_items as pi')
                ->join('proposals as p', 'p.id', '=', 'pi.proposal_id')
                ->join('rfq_items as ri', 'ri.id', '=', 'pi.rfq_item_id')
                ->join('catalogues as c', 'c.id', '=', 'ri.catalogue_id')
                ->where('p.winner_status', 'winner')
                ->where('pi.price_offer', '>', 0)
                ->where(function ($q) use ($terms, $operator, $like) {
                    foreach ($terms as $term) {
                        $q->orWhere('c.name', $operator, $like($term))
                            ->orWhere('c.brand', $operator, $like($term))
                            ->orWhere('c.specifications', $operator, $like($term));
                    }
                })
                ->select(['c.name as catalogue_name', 'c.brand', 'c.specifications', 'c.uom', 'pi.price_offer', 'p.awarded_at'])
                ->orderByDesc('p.awarded_at')
                ->limit(100)
                ->get();

            foreach ($rows->groupBy('catalogue_name') as $itemName => $group) {
                $key = $this->normalizeKey($itemName);
                if (isset($results[$key])) {
                    continue; // PO historis diprioritaskan
                }

                $prices = $group->pluck('price_offer')->map(fn($p) => (float) $p)->filter(fn($p) => $p > 0);
                if ($prices->isEmpty()) {
                    continue;
                }

                $results[$key] = [
                    'item_name' => $itemName,
                    'specifications' => $group->first()?->specifications,
                    'avg_price' => round($prices->average()),
                    'min_price' => $prices->min(),
                    'max_price' => $prices->max(),
                    'last_price' => (float) ($group->first()?->price_offer ?? 0),
                    'uom' => $group->first()?->uom ?? 'unit',
                    'sample_count' => $prices->count(),
                    'source' => 'proposal_winner',
                    'last_date' => $group->first()?->awarded_at,
                ];
            }
        } catch (\Throwable $e) {
            Log::warning('AgenticProcurementService: proposal_items query failed', ['error' => $e->getMessage()]);
        }

        return $results;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Katalog
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Cari katalog relevan. Hanya produk yang dinyatakan cocok (ai_match = true) yang dikembalikan.
     * Jika tidak ada yang cocok, hasil kosong (BUKAN daftar yang salah kategori).
     */
    public function discoverCatalogues(array $intent, array $options = []): array
    {
        try {
            $dbQuery = Catalogue::query()->with('company');

            $dbQuery->whereHas('company', function ($q) {
                $q->where('type', 'vendor')->whereIn('status', ['approved', 'pending']);
            });

            // Buyer memilih katalog secara eksplisit -> tidak perlu penilaian AI.
            if (!empty($options['catalogue_ids'])) {
                $dbQuery->whereIn('id', $options['catalogue_ids']);

                return $dbQuery->get()->map(function (Catalogue $c) {
                    $item = $this->mapCatalogueItem($c);
                    $item['ai_match'] = true;
                    $item['ai_score'] = null;
                    $item['fit_reason'] = 'Dipilih langsung oleh buyer.';
                    return $item;
                })->values()->toArray();
            }

            $terms = array_values(array_unique(array_filter(
                array_map(fn($k) => trim((string) $k), $intent['keywords'] ?? []),
                fn($k) => mb_strlen($k) >= 3
            )));
            $category = $intent['category'] ?? null;
            $brand = $intent['brand'] ?? null;

            if (empty($terms) && !$category && !$brand) {
                return []; // tanpa kata kunci, jangan mengembalikan katalog acak
            }

            $operator = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

            $dbQuery->where(function ($q) use ($terms, $category, $brand, $operator) {
                foreach ($terms as $kw) {
                    $q->orWhere('name', $operator, "%{$kw}%")
                        ->orWhere('item_code', $operator, "%{$kw}%")
                        ->orWhere('specifications', $operator, "%{$kw}%");
                }
                if (empty($terms)) {
                    if ($category) {
                        $q->orWhere('category', $operator, "%{$category}%");
                    }
                    if ($brand) {
                        $q->orWhere('brand', $operator, "%{$brand}%");
                    }
                }
            });

            $results = $dbQuery->limit(20)->get();

            // Guard kategori: buang produk yang JELAS berbeda kategori dari SEMUA target item.
            $targetTexts = [];
            foreach ($intent['target_items'] ?? [] as $t) {
                $targetTexts[] = trim(($t['name'] ?? '') . ' ' . ($t['spec_requirements'] ?? ''));
            }
            if (empty($targetTexts) && !empty($intent['ai_summary'])) {
                $targetTexts[] = (string) $intent['ai_summary'];
            }
            $targetTexts = array_values(array_filter($targetTexts));

            if ($results->isNotEmpty() && !empty($targetTexts)) {
                $results = $results->filter(function (Catalogue $c) use ($targetTexts) {
                    $text = trim(($c->name ?? '') . ' ' . ($c->category ?? ''));
                    foreach ($targetTexts as $target) {
                        if (!$this->isClearlyDifferentCategory($text, $target)) {
                            return true;
                        }
                    }
                    return false;
                })->values();
            }

            if ($results->isEmpty()) {
                return [];
            }

            $ranked = $this->openAi->rankSearchProducts($intent['ai_summary'] ?? '', $results->toArray(), $options['company_id'] ?? null, $intent);
            $rankedById = collect($ranked)->keyBy('product_id');

            $mapped = $results->map(function (Catalogue $p) use ($rankedById) {
                $item = $this->mapCatalogueItem($p);
                $info = $rankedById->get($p->id);

                // Tidak dinilai AI = tidak dianggap cocok.
                $item['ai_match'] = (bool) ($info['is_match'] ?? false);
                $item['ai_score'] = $item['ai_match'] ? (int) ($info['relevance_score'] ?? 0) : null;
                $item['fit_reason'] = $info['fit_reason'] ?? null;

                return $item;
            });

            return $mapped
                ->filter(fn($i) => $i['ai_match'] === true)
                ->sortByDesc('ai_score')
                ->values()
                ->toArray();
        } catch (\Throwable $e) {
            Log::warning('AgenticProcurementService: discoverCatalogues failed', ['error' => $e->getMessage()]);
        }

        return [];
    }

    private function mapCatalogueItem(Catalogue $c): array
    {
        return [
            'id' => $c->id,
            'name' => $c->name,
            'item_code' => $c->item_code,
            'category' => $c->category,
            'brand' => $c->brand,
            'specifications' => $c->specifications,
            'uom' => $c->uom ?? 'unit',
            'image_path' => $c->image_path,
            'image_url' => $c->image_url,
            'vendor' => $c->company?->name,
            'company_id' => $c->company_id,
            'estimated_price' => 0,     // katalog tidak membawa harga; ditentukan oleh enrichPrItems
            'ai_score' => null,
            'ai_match' => false, // baru true setelah dinilai cocok
            'fit_reason' => null,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Item PR
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Susun item PR di SERVER: satu baris per target item buyer.
     * Jika ada katalog yang cocok, atribut diambil dari katalog; jika tidak, dari teks buyer.
     */
    private function buildPrItems(array $intent, array $catalogues, array $options = []): array
    {
        $matched = array_values(array_filter($catalogues, fn($c) => ($c['ai_match'] ?? false) === true));
        usort($matched, fn($a, $b) => ($b['ai_score'] ?? 0) <=> ($a['ai_score'] ?? 0));

        $targets = array_values(array_filter(
            $intent['target_items'] ?? [],
            fn($t) => trim((string) ($t['name'] ?? '')) !== ''
        ));

        $items = [];

        foreach ($targets as $target) {
            $qty = max(1, (int) ($target['quantity'] ?? 1));
            $cat = $this->pickCatalogueForTarget($target, $matched);

            $items[] = $cat
                ? $this->itemFromCatalogue($cat, $qty, $target)
                : $this->itemFromTarget($target, $qty, $intent);
        }

        // Tidak ada target item tetapi ada katalog yang cocok / dipilih buyer.
        if (empty($items) && !empty($matched)) {
            $pool = !empty($options['catalogue_ids']) ? $matched : [$matched[0]];
            foreach ($pool as $cat) {
                $items[] = $this->itemFromCatalogue($cat, 1, null);
            }
        }

        return $items;
    }

    private function pickCatalogueForTarget(array $target, array $matched): ?array
    {
        $name = (string) ($target['name'] ?? '');
        $brand = mb_strtolower(trim((string) ($target['brand'] ?? '')));
        $tokens = $this->tokens($name);
        if (empty($tokens)) {
            return null;
        }

        $best = null;
        $bestScore = 0.0;

        foreach ($matched as $cat) { // sudah terurut ai_score; seri dimenangkan yang lebih awal
            $catBrand = mb_strtolower(trim((string) ($cat['brand'] ?? '')));
            if ($brand !== '' && $catBrand !== $brand) {
                continue;
            }

            $hay = trim(($cat['name'] ?? '') . ' ' . ($cat['category'] ?? '') . ' ' . ($cat['specifications'] ?? ''));
            if ($this->isClearlyDifferentCategory($hay, $name)) {
                continue;
            }

            $overlap = count(array_intersect($tokens, $this->tokens($hay))) / count($tokens);
            if ($overlap >= 0.5 && $overlap > $bestScore) {
                $best = $cat;
                $bestScore = $overlap;
            }
        }

        return $best;
    }

    private function itemFromCatalogue(array $cat, int $qty, ?array $target): array
    {
        return [
            'catalogue_id' => $cat['id'],
            'name' => $cat['name'],
            'item_code' => $cat['item_code'] ?? null,
            'category' => $cat['category'] ?? null,
            'brand' => $cat['brand'] ?? null,
            'detailed_specs' => $cat['specifications'] ?? ($target['spec_requirements'] ?? null),
            'qty' => $qty,
            'uom' => $cat['uom'] ?? ($target['uom'] ?? 'unit'),
            'reason' => $cat['fit_reason'] ?? null,
            'image_url' => $cat['image_url'] ?? null,
            'vendor' => $cat['vendor'] ?? null,
            '_budget_hint_idr' => $target['budget_hint_idr'] ?? null,
        ];
    }

    private function itemFromTarget(array $target, int $qty, array $intent): array
    {
        return [
            'catalogue_id' => null,
            'name' => $target['name'],
            'item_code' => null,
            'category' => $intent['category'] ?? null,
            'brand' => $target['brand'] ?? null,
            'detailed_specs' => $target['spec_requirements'] ?? null,
            'qty' => $qty,
            'uom' => $target['uom'] ?? 'unit',
            'reason' => null,
            'image_url' => null,
            'vendor' => null,
            '_budget_hint_idr' => $target['budget_hint_idr'] ?? null,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Penetapan harga
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Urutan sumber harga (semua ditentukan SERVER, tidak pernah dari AI):
     *  1. historical_reference  : histori PO / proposal pemenang
     *  2. web_market_reference  : median web (min. 3 sampel relevan)
     *  3. buyer_budget          : anggaran per-item yang disebut eksplisit oleh buyer
     *  4. rfq_required          : tidak ada sumber -> harga 0
     */
    private function enrichPrItems(array $items, array $webSearchResults, array $historicalPrices): array
    {
        $expectedDate = now()->addDays(14)->toDateString();

        return array_map(function ($item) use ($webSearchResults, $historicalPrices, $expectedDate) {
            $qty = max(1, (int) ($item['qty'] ?? 1));
            $name = (string) ($item['name'] ?? '');
            $brand = (string) ($item['brand'] ?? '');
            $price = 0.0;
            $status = 'rfq_required';
            $note = null;
            $webEntry = $this->bestWebEntry($name, $brand, $webSearchResults, false);

            // 1) Histori
            $hist = $this->matchHistorical($item, $historicalPrices);
            if ($hist) {
                $price = (float) $hist['avg_price'];
                $status = 'historical_reference';
                $note = sprintf(
                    'Referensi %s: Rp %s - Rp %s dari %d transaksi%s',
                    $hist['source'] === 'historical_po' ? 'PO historis' : 'penawaran tender menang',
                    number_format((float) $hist['min_price'], 0, ',', '.'),
                    number_format((float) $hist['max_price'], 0, ',', '.'),
                    (int) $hist['sample_count'],
                    !empty($hist['last_date']) ? ' (terakhir ' . $hist['last_date'] . ')' : ''
                );
            }

            // 2a) Web (hasil riset Step 3)
            if ($price <= 0) {
                $pricedEntry = $this->bestWebEntry($name, $brand, $webSearchResults, true);
                $wp = $pricedEntry['web_prices'] ?? [];
                if ($pricedEntry && (float) ($wp['avg_price'] ?? 0) > 0) {
                    $price = (float) $wp['avg_price'];
                    $status = 'web_market_reference';
                    $note = $this->webPriceNote('Median harga web', $wp);
                }
            }

            // 2b) Web on-demand (item di luar riset Step 3). Memakai fungsi yang sama: min. 3 sampel relevan.
            if ($price <= 0 && $this->webSearch->isEnabled() && trim($name) !== '') {
                try {
                    $live = $this->webSearch->searchMarketPrice($name, $brand);
                    if (!empty($live['raw_results'])) {
                        $webEntry = [
                            'web_prices' => $live,
                            'results' => $live['raw_results'],
                        ];
                    }
                    if ((float) ($live['avg_price'] ?? 0) > 0) {
                        $price = (float) $live['avg_price'];
                        $status = 'web_market_reference';
                        $note = $this->webPriceNote('Median harga web (riset on-demand)', $live);
                    }
                } catch (\Throwable $e) {
                    Log::warning('AgenticProcurementService: on-demand price search failed', ['error' => $e->getMessage()]);
                }
            }

            $webPrices = $webEntry['web_prices'] ?? [];
            $webAverage = (float) ($webPrices['avg_price'] ?? 0);
            $webSources = array_map(fn($source) => [
                'title' => $source['title'] ?? '',
                'link' => $source['link'] ?? '',
                'source' => $source['source'] ?? '',
                'price' => (float) ($source['price'] ?? 0) > 0 ? (float) $source['price'] : null,
            ], array_slice($webEntry['results'] ?? [], 0, 10));

            // 3) Anggaran per-item yang disebut buyer (sudah diverifikasi terhadap teks buyer di OpenAiService)
            if ($price <= 0 && !empty($item['_budget_hint_idr'])) {
                $hint = (float) $item['_budget_hint_idr'];
                if ($hint > 0) {
                    $price = round($hint / $qty);
                    $status = 'buyer_budget';
                    $note = 'Berdasarkan anggaran yang disebutkan buyer untuk item ini.';
                }
            }

            if ($price <= 0) {
                $price = 0.0;
                $status = 'rfq_required';
                $note = 'Tidak ada referensi harga. Harga ditentukan melalui RFQ.';
            }

            return [
                'catalogue_id' => $item['catalogue_id'] ?? null,
                'name' => $name,
                'item_code' => $item['item_code'] ?? null,
                'category' => $item['category'] ?? null,
                'brand' => $item['brand'] ?? null,
                'detailed_specs' => $item['detailed_specs'] ?? null,
                'qty' => $qty,
                'uom' => $item['uom'] ?? 'unit',
                'estimated_price' => $price,
                'price_status' => $status,
                'price_note' => $note,
                'web_price_min' => $webAverage > 0 ? ($webPrices['min_price'] ?? null) : null,
                'web_price_max' => $webAverage > 0 ? ($webPrices['max_price'] ?? null) : null,
                'web_price_avg' => $webAverage > 0 ? $webAverage : null,
                'web_price_sample_count' => (int) ($webPrices['sample_count'] ?? 0),
                'web_sources' => $webSources,
                'expected_date' => $expectedDate,
                'reason' => $item['reason'] ?? null,
                'image_url' => $item['image_url'] ?? null,
                'vendor' => $item['vendor'] ?? null,
            ];
        }, $items);
    }

    private function webPriceNote(string $label, array $wp): string
    {
        return sprintf(
            '%s: Rp %s (rentang Rp %s - Rp %s, %d sampel)',
            $label,
            number_format((float) $wp['avg_price'], 0, ',', '.'),
            number_format((float) ($wp['min_price'] ?? $wp['avg_price']), 0, ',', '.'),
            number_format((float) ($wp['max_price'] ?? $wp['avg_price']), 0, ',', '.'),
            (int) ($wp['sample_count'] ?? count($wp['sources'] ?? []))
        );
    }

    /**
     * Cocokkan item dengan histori: nama sama persis, atau salah satu memuat yang lain
     * (string yang lebih pendek minimal 8 karakter agar nama generik tidak ikut cocok).
     */
    private function matchHistorical(array $item, array $historical): ?array
    {
        $name = mb_strtolower(trim((string) ($item['name'] ?? '')));
        $brand = mb_strtolower(trim((string) ($item['brand'] ?? '')));
        if (mb_strlen($name) < 6) {
            return null;
        }

        foreach ($historical as $key => $h) {
            $key = mb_strtolower((string) $key);

            if ($this->isClearlyDifferentCategory($name, $key)) {
                continue;
            }
            if ($brand !== '' && !str_contains($key, $brand)) {
                continue;
            }

            if ($name === $key) {
                return $h;
            }
            if (
                min(mb_strlen($name), mb_strlen($key)) >= 8
                && (str_contains($name, $key) || str_contains($key, $name))
            ) {
                return $h;
            }
        }

        return null;
    }

    /**
     * Cari entri web yang cocok dengan sebuah produk. Ketat:
     *  - beda kategori -> ditolak
     *  - item bermerek  -> entri harus bermerek sama (60 + 40 x overlap token)
     *  - item tanpa merek -> hanya entri tanpa merek (100 x overlap token)
     *  - skor minimum MIN_WEB_MATCH_SCORE; tidak ada fuzzy prefix.
     *
     * @param bool $requirePrice true = hanya entri yang punya statistik harga valid
     */
    private function bestWebEntry(string $name, string $brand, array $webSearchResults, bool $requirePrice): ?array
    {
        $nameTokens = $this->tokens($name);
        if (empty($nameTokens)) {
            return null;
        }

        $brand = mb_strtolower(trim($brand));
        $best = null;
        $bestScore = 0.0;

        foreach ($webSearchResults as $key => $data) {
            if ($key === '__general__') {
                continue;
            }

            $dataBrand = mb_strtolower(trim((string) ($data['brand'] ?? '')));
            $dataName = mb_strtolower(trim((string) ($data['item_name'] ?? $key)));

            if ($requirePrice && (float) ($data['web_prices']['avg_price'] ?? 0) <= 0) {
                continue;
            }
            if ($this->isClearlyDifferentCategory(trim($name . ' ' . $brand), trim($dataName . ' ' . $dataBrand))) {
                continue;
            }

            if ($brand === '') {
                if ($dataBrand !== '') {
                    continue; // jangan pakai harga merek tertentu untuk item generik
                }
            } else {
                if ($dataBrand !== '' && $dataBrand !== $brand) {
                    continue;
                }
                if ($dataBrand === '' && !str_contains($dataName, $brand)) {
                    continue;
                }
            }

            $overlap = count(array_intersect($nameTokens, $this->tokens($dataName))) / count($nameTokens);
            $score = $brand === '' ? $overlap * 100 : 60 + $overlap * 40;

            if ($score >= self::MIN_WEB_MATCH_SCORE && $score > $bestScore) {
                $best = $data;
                $bestScore = $score;
            }
        }

        return $best;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Buat RFQ
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Simpan PR draft sebagai RFQ resmi.
     */
    public function createPrFromDraft(Company $buyerCompany, array $prDraft, ?string $userId = null): Rfq
    {
        $title = $prDraft['title'] ?? ('Purchase Requisition - ' . date('Y-m-d H:i'));
        $description = (string) ($prDraft['description'] ?? '');

        if (!empty($prDraft['business_justification'])) {
            $description .= "\n\n**Justifikasi Bisnis:**\n" . $prDraft['business_justification'];
        }
        if (!empty($prDraft['manager_notes'])) {
            $description .= "\n\n**Catatan Manager:**\n" . $prDraft['manager_notes'];
        }

        $suggested = $prDraft['suggested_items'] ?? [];

        // Hanya terima catalogue_id yang benar-benar ada di database.
        $claimedIds = array_values(array_filter(array_column($suggested, 'catalogue_id')));
        $validIds = empty($claimedIds)
            ? []
            : Catalogue::whereIn('id', $claimedIds)->pluck('id')->map(fn($id) => (string) $id)->all();

        $items = [];
        foreach ($suggested as $item) {
            $catalogueId = $item['catalogue_id'] ?? null;
            if ($catalogueId !== null && !in_array((string) $catalogueId, $validIds, true)) {
                $catalogueId = null;
            }

            if ($catalogueId === null) {
                $name = trim((string) ($item['name'] ?? ''));
                if ($name === '') {
                    continue;
                }

                // Dibatasi ke perusahaan buyer sendiri: jangan menempel ke katalog perusahaan lain.
                $catalogue = Catalogue::firstOrCreate(
                    ['company_id' => $buyerCompany->id, 'name' => $name],
                    [
                        'item_code' => $item['item_code'] ?? ('PR-ITEM-' . strtoupper(substr(md5(uniqid('', true)), 0, 6))),
                        'category' => $item['category'] ?? 'General Procurement',
                        'brand' => $item['brand'] ?? null,
                        'specifications' => $item['detailed_specs'] ?? null,
                        'uom' => $item['uom'] ?? 'unit',
                    ]
                );
                $catalogueId = $catalogue->id;
            }

            $items[] = [
                'catalogue_id' => $catalogueId,
                'qty' => max(1, (int) ($item['qty'] ?? 1)),
                'estimated_price' => (float) ($item['estimated_price'] ?? 0),
                'expected_date' => $item['expected_date'] ?? now()->addDays(14)->toDateString(),
            ];
        }

        if (empty($items)) {
            throw new \InvalidArgumentException('Tidak ada item yang dapat dimasukkan ke dalam PR.');
        }

        return $this->createRfqAction->execute(
            buyerCompany: $buyerCompany,
            title: $title,
            description: $description,
            cartItems: $items,
            userId: $userId,
            status: 'pending_approval',
            durationDays: (int) ($prDraft['duration_days'] ?? 7),
            documentPath: null,
            deliveryPoint: (string) ($prDraft['delivery_point_recommendation'] ?? $buyerCompany->address ?? ''),
            department: $prDraft['department'] ?? 'Procurement'
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helper
    // ─────────────────────────────────────────────────────────────────────────

    private function normalizeKey(string $s): string
    {
        return mb_strtolower(trim($s));
    }

    private function tokens(string $text): array
    {
        $text = preg_replace('/smart[\s-]*bulb/iu', 'smart bulb', $text);
        $text = mb_strtolower(preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text));
        $tokens = preg_split('/\s+/u', trim($text)) ?: [];

        return array_values(array_unique(array_filter(
            $tokens,
            fn($t) => mb_strlen($t) >= 2 && !in_array($t, self::STOPWORDS, true)
        )));
    }

    private function primaryFamily(string $text): ?string
    {
        $best = null;
        $bestPos = PHP_INT_MAX;

        foreach (self::FAMILIES as $family => $keywords) {
            foreach ($keywords as $kw) {
                $pos = mb_strpos($text, $kw);
                if ($pos !== false && $pos < $bestPos) {
                    $bestPos = $pos;
                    $best = $family;
                }
            }
        }

        return $best;
    }

    /**
     * True jika dua teks JELAS membicarakan kategori produk utama yang berbeda
     * (mis. "Lexar NVMe SSD" vs "Mini PC Intel i5").
     */
    private function isClearlyDifferentCategory(string $textA, string $textB): bool
    {
        $a = mb_strtolower(trim($textA));
        $b = mb_strtolower(trim($textB));
        if ($a === '' || $b === '') {
            return false;
        }

        $familyA = $this->primaryFamily($a);
        $familyB = $this->primaryFamily($b);

        return $familyA !== null && $familyB !== null && $familyA !== $familyB;
    }
}