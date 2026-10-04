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
 * Layanan orkestrasi Agentic AI Procurement otonom:
 * 1. Analisis Kebutuhan (Intent & Requirements Discovery)
 * 2. Pencarian Katalog Otomatis (Autonomous Item Search & Matching)
 * 3. Komparasi Produk Mandiri (Multi-Product Comparison & Tradeoff Matrix)
 * 4. Pembuatan Dokumen PR Lengkap (PR Formulation with Deep Descriptions & Specs)
 * 5. Eksekusi Pembuatan PR ke Sistem Huntr
 */
class AgenticProcurementService
{
    public function __construct(
        private readonly OpenAiService      $openAi,
        private readonly BraveSearchService $webSearch,
        private readonly CreateRfqAction    $createRfqAction
    ) {
    }

    /**
     * Menjalankan full autonomous workflow: Search -> Compare -> Formulate PR.
     *
     * @param string $query Natural language procurement requirements
     * @param array $options [company_id, user_id, auto_create_pr, catalogue_ids]
     * @return array
     */
    public function runFullWorkflow(string $query, array $options = []): array
    {
        $steps = [];

        // Step 1: Analisis Kebutuhan
        $intent = $this->openAi->extractSearchIntent($query);
        $steps[] = [
            'step' => 'intent_analysis',
            'title' => 'Analisis Kebutuhan & Spesifikasi',
            'status' => 'completed',
            'summary' => $intent['ai_summary'] ?? 'Analisis kebutuhan selesai.',
            'target_items' => $intent['target_items'] ?? [],
        ];

        // Step 2: Query harga historis dari PO & Proposal database (ground truth)
        $historicalPrices = $this->queryHistoricalPrices($intent);
        $historicalCount = count($historicalPrices);
        $steps[] = [
            'step'        => 'historical_price_lookup',
            'title'       => 'Referensi Harga Historis (PO & Tender)',
            'status'      => 'completed',
            'total_found' => $historicalCount,
            'summary'     => $historicalCount > 0
                ? "Ditemukan {$historicalCount} referensi harga nyata dari transaksi PO & penawaran vendor sebelumnya."
                : 'Tidak ada riwayat transaksi yang cocok di database. AI akan menggunakan estimasi harga pasar wajar.',
            'sources'     => array_values(array_map(fn($v) => [
                'name'       => $v['item_name'],
                'avg_price'  => $v['avg_price'],
                'last_price' => $v['last_price'],
                'source'     => $v['source'],
                'samples'    => $v['sample_count'],
            ], $historicalPrices)),
        ];

        // Step 3: Brave Search — cari produk, harga & spesifikasi dari internet
        $webSearchResults  = [];
        $webSearchSummary  = 'Brave Search tidak dikonfigurasi.';
        if ($this->webSearch->isEnabled()) {
            $targetItems = $intent['target_items'] ?? [];
            $keywords    = $intent['keywords']     ?? [];

            // Cari harga & alternatif untuk setiap target item
            foreach (array_slice($targetItems, 0, 5) as $targetItem) {
                $itemName = $targetItem['name'] ?? '';
                $brand    = $targetItem['brand'] ?? '';
                $specReq  = $targetItem['spec_requirements'] ?? ($targetItem['specs_hint'] ?? '');
                if (empty($itemName)) continue;

                // Bangun search query yang spesifik agar tidak hanya mencari nama umum (misal: "Excavator Komatsu PC200")
                $fullSearchName = trim("{$brand} {$itemName} {$specReq}");

                $priceData = $this->webSearch->searchMarketPrice(
                    $fullSearchName,
                    $brand,
                    $specReq
                );

                if (!empty($priceData['raw_results'])) {
                    $webSearchResults[strtolower(trim($itemName))] = [
                        'item_name'  => $fullSearchName,
                        'brand'      => $brand,
                        'web_prices' => $priceData,
                        'results'    => array_slice($priceData['raw_results'], 0, 8),
                    ];
                }
            }

            // Jika target_items kosong, fallback ke pencarian kategori
            if (empty($webSearchResults) && !empty($keywords)) {
                $altQuery    = implode(' ', array_slice($keywords, 0, 4));
                $altResults  = $this->webSearch->searchProducts(
                    "{$altQuery} harga B2B distributor resmi Indonesia",
                    8
                );
                if (!empty($altResults)) {
                    $webSearchResults['__general__'] = [
                        'item_name' => $altQuery,
                        'results'   => $altResults,
                    ];
                }
            }

            $totalWebFound = count(array_filter(
                $webSearchResults,
                fn($v) => !empty($v['results'])
            ));
            $webSearchSummary = $totalWebFound > 0
                ? "Brave Search menemukan {$totalWebFound} kategori produk dengan harga & spesifikasi terkini dari internet."
                : 'Brave Search aktif namun tidak menemukan hasil yang relevan untuk query ini.';
        }

        $steps[] = [
            'step'        => 'web_search',
            'title'       => 'Brave Search — Harga & Spek Real-time',
            'status'      => 'completed',
            'total_found' => count($webSearchResults),
            'summary'     => $webSearchSummary,
            'sources'     => collect($webSearchResults)
                ->flatMap(fn($d) => array_slice($d['results'] ?? [], 0, 2))
                ->map(fn($r) => ['title' => $r['title'] ?? '', 'link' => $r['link'] ?? '', 'price' => $r['price'] ?? 0])
                ->values()
                ->toArray(),
        ];


        // Step 4: Cari produk di katalog database
        $foundCatalogues = $this->discoverCatalogues($intent, $options);
        $steps[] = [
            'step'        => 'catalogue_discovery',
            'title'       => 'Pencarian Katalog Otomatis',
            'status'      => 'completed',
            'total_found' => count($foundCatalogues),
            'summary'     => count($foundCatalogues) > 0
                ? 'Ditemukan ' . count($foundCatalogues) . ' produk katalog yang relevan dengan spesifikasi.'
                : 'Tidak ada produk langsung di database, AI menggenerasi spesifikasi item standar industri.',
        ];

        // Step 5: Evaluasi & Komparasi Produk
        $comparison = null;
        if (count($foundCatalogues) >= 2) {
            $candidatesToCompare = array_slice($foundCatalogues, 0, 5);
            $comparison = $this->openAi->compareProducts($candidatesToCompare, $query, $webSearchResults);

            // Enrich setiap item di comparison_matrix dengan data Brave Search:
            // gambar thumbnail, harga pasar web, link sumber nyata
            if (!empty($comparison['comparison_matrix'])) {
                $catalogueById = collect($foundCatalogues)->keyBy('id');

                $comparison['comparison_matrix'] = array_map(function ($matrixItem) use ($catalogueById, $webSearchResults) {
                    $catId = $matrixItem['catalogue_id'] ?? null;
                    $cat   = $catId ? $catalogueById->get($catId) : null;

                    // ── Ambil thumbnail dari katalog internal jika ada ──
                    $thumbnail = $cat['image_url'] ?? null;

                    // ── Cari data Brave yang cocok dengan nama produk ──
                    $productName = strtolower(trim($matrixItem['product_name'] ?? ''));
                    $webSources  = [];
                    $webPriceMin = null;
                    $webPriceMax = null;
                    $webPriceAvg = null;

                    foreach ($webSearchResults as $key => $data) {
                        if ($key === '__general__') continue;
                        $dataName = strtolower(trim($data['item_name'] ?? $key));

                        $isMatch = str_contains($productName, $dataName)
                            || str_contains($dataName, $productName)
                            || (strlen($productName) >= 5 && str_contains($dataName, substr($productName, 0, 6)))
                            || (strlen($dataName)    >= 5 && str_contains($productName, substr($dataName, 0, 6)));

                        if (!$isMatch) continue;

                        // Ambil top 3 hasil web sebagai sumber referensi
                        foreach (array_slice($data['results'] ?? [], 0, 3) as $r) {
                            $webSources[] = [
                                'title'     => $r['title']     ?? '',
                                'link'      => $r['link']      ?? '',
                                'snippet'   => mb_substr($r['snippet'] ?? '', 0, 140),
                                'price'     => $r['price']     ?? 0,
                                'source'    => $r['source']    ?? '',
                                'thumbnail' => $r['thumbnail'] ?? null,
                            ];
                            // Pakai thumbnail dari Brave jika katalog tidak punya gambar
                            if (!$thumbnail && !empty($r['thumbnail'])) {
                                $thumbnail = $r['thumbnail'];
                            }
                        }

                        // Harga range dari Brave
                        $wp = $data['web_prices'] ?? [];
                        if (!empty($wp['avg_price'])) {
                            $webPriceMin = $wp['min_price'] ?? null;
                            $webPriceMax = $wp['max_price'] ?? null;
                            $webPriceAvg = $wp['avg_price'] ?? null;
                        }
                        break; // satu match sudah cukup
                    }

                    return array_merge($matrixItem, [
                        'thumbnail'       => $thumbnail,
                        'web_price_min'   => $webPriceMin,
                        'web_price_max'   => $webPriceMax,
                        'web_price_avg'   => $webPriceAvg,
                        'web_sources'     => $webSources,
                        'vendor_name'     => $matrixItem['vendor_name'] ?? ($cat['vendor'] ?? null),
                    ]);
                }, $comparison['comparison_matrix']);
            }

            $steps[] = [
                'step'     => 'product_comparison',
                'title'    => 'Komparasi & Evaluasi Produk',
                'status'   => 'completed',
                'summary'  => $comparison['executive_summary'] ?? 'Evaluasi komparasi produk selesai.',
                'winner_id' => $comparison['winner_id'] ?? null,
            ];
        } else {
            $steps[] = [
                'step'    => 'product_comparison',
                'title'   => 'Evaluasi Produk Tunggal',
                'status'  => 'completed',
                'summary' => 'Kandidat produk telah dievaluasi dan siap diproses ke dokumen PR.',
            ];
        }

        // Step 6: Susun Dokumen PR & Deskripsi Lengkap
        $company = !empty($options['company_id']) ? Company::find($options['company_id']) : null;
        $context = [
            'company_name'                => $company?->name,
            'address'                     => $company?->address,
            'department'                  => $intent['department'] ?? 'Procurement',
            'estimated_total_budget_idr'  => $intent['estimated_total_budget_idr'] ?? null,
            'target_items'                => $intent['target_items'] ?? [],
        ];

        $prDraft = $this->openAi->generatePrDraft($query, $foundCatalogues, $context, $historicalPrices, $webSearchResults);

        // Enrich suggested items jika ada mapping katalog
        $enrichedItems = $this->enrichPrItems($prDraft['suggested_items'] ?? [], $foundCatalogues, $intent, $webSearchResults);
        $prDraft['suggested_items'] = $enrichedItems;

        // Recalculate total budget (Zero Hallucination)
        $hasExplicitBudget = !empty($intent['estimated_total_budget_idr']) && (float) $intent['estimated_total_budget_idr'] > 0;
        $calculatedTotal = collect($enrichedItems)->sum(fn($i) => ($i['qty'] ?? 1) * ($i['estimated_price'] ?? 0));

        if ($calculatedTotal > 0) {
            $prDraft['estimated_total_budget'] = $calculatedTotal;
        } elseif ($hasExplicitBudget) {
            $prDraft['estimated_total_budget'] = (float) $intent['estimated_total_budget_idr'];
        } else {
            $prDraft['estimated_total_budget'] = 0;
        }

        $steps[] = [
            'step' => 'pr_formulation',
            'title' => 'Penyusunan Purchase Requisition (PR)',
            'status' => 'completed',
            'summary' => 'Draft PR resmi dengan deskripsi detail, justifikasi, dan rincian item telah selesai disusun.',
            'pr_title' => $prDraft['title'] ?? '',
            'price_sources_used' => collect($prDraft['suggested_items'] ?? [])
                ->groupBy('price_status')
                ->map->count()
                ->toArray(),
        ];

        // Step 5: Eksekusi otomatis jika requested
        $createdRfq = null;
        if (!empty($options['auto_create_pr']) && $company && $company->type === 'buyer') {
            try {
                $createdRfq = $this->createPrFromDraft(
                    $company,
                    $prDraft,
                    $options['user_id'] ?? null
                );
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
            'success'        => true,
            'query'          => $query,
            'intent'         => $intent,
            'catalogues'     => $foundCatalogues,
            'comparison'     => $comparison,
            'pr_draft'       => $prDraft,
            'web_search'     => $webSearchResults,
            'created_rfq'    => $createdRfq ? $createdRfq->load(['items.catalogue']) : null,
            'workflow_steps' => $steps,
        ];
    }

    /**
     * Interaktif Chat dengan Agentic Procurement AI.
     */
    public function chatWithAgent(array $messages, array $options = []): array
    {
        $systemInstruction = <<<INSTRUCTION
Kamu adalah "Huntr Agentic Procurement AI" — asisten pengadaan barang & jasa B2B cerdas di platform Huntr.
Tugas utamamu adalah:
1. Membantu buyer merumuskan kebutuhan pengadaan (spesifikasi, kuantitas, estimasi budget IDR).
2. Membantu mencari barang yang tepat, membandingkan beberapa opsi/merek barang secara objektif (kelebihan, kekurangan, harga).
3. Membantu menyusun dokumen Purchase Requisition (PR) resmi dengan deskripsi lengkap, justifikasi bisnis, dan rincian line item.
4. Menjawab pertanyaan teknis mengenai spesifikasi barang, standar pengadaan, dan perbandingan merek.

Berbicaralah dengan nada ramah, profesional, solutif, dan terstruktur dalam Bahasa Indonesia formal.
Gunakan format markdown yang rapi (bold, bullet points, table jika relevan).
INSTRUCTION;

        $reply = $this->openAi->chat($messages, $systemInstruction);

        // Jika user memberi instruksi yang mengarah ke pembuatan PR atau pencarian, sertakan trigger info
        $lastUserMsg = end($messages)['content'] ?? '';
        $hasProcurementIntent = (
            str_contains(strtolower($lastUserMsg), 'buat pr') ||
            str_contains(strtolower($lastUserMsg), 'bikin pr') ||
            str_contains(strtolower($lastUserMsg), 'cari') ||
            str_contains(strtolower($lastUserMsg), 'butuh') ||
            str_contains(strtolower($lastUserMsg), 'bandingkan')
        );

        return [
            'success' => true,
            'reply' => $reply,
            'has_procurement_intent' => $hasProcurementIntent,
        ];
    }

    /**
     * Query harga historis dari PO operasional & Historical PO database.
     *
     * Mencari referensi harga nyata (ground truth) berdasarkan keyword nama item
     * dari transaksi yang benar-benar pernah terjadi di platform Huntr.
     *
     * Sumber data (prioritas):
     * 1. historical_po_items — PO historis yang diimport
     * 2. proposal_items dari proposal yang menang (winner) — harga penawaran nyata
     *
     * @param array $intent Hasil extractSearchIntent (keywords, target_items, dll)
     * @return array Keyed by keyword: [item_name, avg_price, min_price, max_price, last_price, sample_count, source]
     */
    public function queryHistoricalPrices(array $intent): array
    {
        $keywords = $intent['keywords'] ?? [];
        $targetItems = $intent['target_items'] ?? [];
        $results = [];

        // Gabungkan semua keyword & nama item target untuk pencarian
        $searchTerms = array_unique(array_filter(
            array_merge(
                array_map('strtolower', $keywords),
                array_map(fn($t) => strtolower($t['name'] ?? ''), $targetItems)
            ),
            fn($t) => strlen($t) >= 3
        ));

        if (empty($searchTerms)) {
            return [];
        }

        $operator = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

        // --- Sumber 1: historical_po_items (PO historis yang diimport) ---
        try {
            $historicalQuery = HistoricalPoItem::query()
                ->whereNotNull('inventory_name')
                ->where('unit_price', '>', 0)
                ->where(function ($q) use ($searchTerms, $operator) {
                    foreach ($searchTerms as $term) {
                        $q->orWhere('inventory_name', $operator, "%{$term}%")
                            ->orWhere('inventory_code', $operator, "%{$term}%")
                            ->orWhere('specifications', $operator, "%{$term}%");
                    }
                })
                ->select([
                    'inventory_name',
                    'specifications',
                    'uom',
                    'unit_price',
                    'currency',
                    'order_date',
                ])
                ->orderByDesc('order_date')
                ->limit(100)
                ->get();

            // Grouping per inventory_name dan hitung statistik harga
            foreach ($historicalQuery->groupBy('inventory_name') as $itemName => $rows) {
                $prices = $rows->pluck('unit_price')->map(fn($p) => (float) $p)->filter(fn($p) => $p > 0);
                if ($prices->isEmpty())
                    continue;

                $key = strtolower(trim($itemName));
                // Convert IDR if needed (exchange_rate already in items)
                $results[$key] = [
                    'item_name' => $itemName,
                    'specifications' => $rows->first()?->specifications ?? null,
                    'avg_price' => round($prices->average()),
                    'min_price' => $prices->min(),
                    'max_price' => $prices->max(),
                    'last_price' => (float) ($rows->first()?->unit_price ?? 0),
                    'uom' => $rows->first()?->uom ?? 'unit',
                    'sample_count' => $prices->count(),
                    'source' => 'historical_po',
                    'last_date' => $rows->first()?->order_date?->toDateString() ?? null,
                ];
            }
        } catch (\Throwable $e) {
            Log::warning('AgenticProcurementService: queryHistoricalPrices historical_po_items failed', [
                'error' => $e->getMessage(),
            ]);
        }

        // --- Sumber 2: proposal_items dari proposal yang menang (winner) ---
        try {
            // Ambil RFQ items yang katalognya cocok dengan keyword
            $winnerProposalItems = DB::table('proposal_items as pi')
                ->join('proposals as p', 'p.id', '=', 'pi.proposal_id')
                ->join('rfq_items as ri', 'ri.id', '=', 'pi.rfq_item_id')
                ->join('catalogues as c', 'c.id', '=', 'ri.catalogue_id')
                ->where('p.winner_status', 'winner')
                ->where('pi.price_offer', '>', 0)
                ->where(function ($q) use ($searchTerms, $operator) {
                    foreach ($searchTerms as $term) {
                        $q->orWhere('c.name', $operator, "%{$term}%")
                            ->orWhere('c.brand', $operator, "%{$term}%")
                            ->orWhere('c.specifications', $operator, "%{$term}%");
                    }
                })
                ->select([
                    'c.name as catalogue_name',
                    'c.brand',
                    'c.specifications',
                    'c.uom',
                    'pi.price_offer',
                    'p.awarded_at',
                ])
                ->orderByDesc('p.awarded_at')
                ->limit(100)
                ->get();

            // Grouping per catalogue_name
            foreach ($winnerProposalItems->groupBy('catalogue_name') as $itemName => $rows) {
                $prices = $rows->pluck('price_offer')->map(fn($p) => (float) $p)->filter(fn($p) => $p > 0);
                if ($prices->isEmpty())
                    continue;

                $key = strtolower(trim($itemName));

                // Jika sudah ada dari historical_po, gabungkan (historical_po lebih prioritas)
                if (isset($results[$key])) {
                    // Historical PO sudah ada — tidak perlu override, tapi tambah sample info
                    continue;
                }

                $results[$key] = [
                    'item_name' => $itemName,
                    'specifications' => $rows->first()?->specifications ?? null,
                    'avg_price' => round($prices->average()),
                    'min_price' => $prices->min(),
                    'max_price' => $prices->max(),
                    'last_price' => (float) ($rows->first()?->price_offer ?? 0),
                    'uom' => $rows->first()?->uom ?? 'unit',
                    'sample_count' => $prices->count(),
                    'source' => 'proposal_winner',
                    'last_date' => $rows->first()?->awarded_at ?? null,
                ];
            }
        } catch (\Throwable $e) {
            Log::warning('AgenticProcurementService: queryHistoricalPrices proposal_items failed', [
                'error' => $e->getMessage(),
            ]);
        }

        return $results;
    }

    /**
     * Cari katalog relevan di database berdasarkan intent.
     */
    public function discoverCatalogues(array $intent, array $options = []): array
    {
        try {
            $dbQuery = Catalogue::query()->with('company');

            // Filter vendor valid
            $dbQuery->whereHas('company', function ($q) {
                $q->where('type', 'vendor')
                    ->whereIn('status', ['approved', 'pending']);
            });

            // Filter jika user memberikan ID katalog spesifik
            if (!empty($options['catalogue_ids'])) {
                $dbQuery->whereIn('id', $options['catalogue_ids']);
                return $dbQuery->get()->map(fn(Catalogue $c) => $this->mapCatalogueItem($c))->toArray();
            }

            $keywords = $intent['keywords'] ?? [];
            $category = $intent['category'] ?? null;
            $brand = $intent['brand'] ?? null;

            $operator = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

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

            $results = $dbQuery->limit(20)->get();

            // Re-ranking dengan AI jika ada kandidat
            if ($results->isNotEmpty()) {
                $companyId = $options['company_id'] ?? null;
                $ranked = $this->openAi->rankSearchProducts($intent['ai_summary'] ?? '', $results->toArray(), $companyId);
                $rankedById = collect($ranked)->keyBy('product_id');

                $mapped = $results->map(function (Catalogue $p) use ($rankedById) {
                    $item = $this->mapCatalogueItem($p);
                    $rankInfo = $rankedById->get($p->id);
                    if ($rankInfo) {
                        $aiMatch = $rankInfo['is_match'] ?? true;
                        $item['ai_match'] = $aiMatch;
                        $item['ai_score'] = (int) ($rankInfo['relevance_score'] ?? 75);
                        $item['fit_reason'] = $rankInfo['fit_reason'] ?? null;
                        // AI selalu return 0 untuk harga — harga dikelola enrichPrItems
                    }
                    return $item;
                });

                // Filter: prioritaskan ai_match=true, tapi jika semua false tetap tampilkan semua
                $matched = $mapped->filter(fn($i) => ($i['ai_match'] ?? true) === true);
                $finalList = $matched->isNotEmpty() ? $matched : $mapped;

                return $finalList->sortByDesc('ai_score')->values()->toArray();
            }
        } catch (\Throwable $e) {
            Log::warning('AgenticProcurementService: discoverCatalogues fallback', ['error' => $e->getMessage()]);
        }

        return [];
    }

    /**
     * Simpan PR Draft langsung ke database sebagai RFQ resmi.
     */
    public function createPrFromDraft(Company $buyerCompany, array $prDraft, ?string $userId = null): Rfq
    {
        $title = $prDraft['title'] ?? 'Purchase Requisition - ' . date('Y-m-d H:i');
        $description = $prDraft['description'] ?? '';

        if (!empty($prDraft['business_justification'])) {
            $description .= "\n\n**Justifikasi Bisnis:**\n" . $prDraft['business_justification'];
        }
        if (!empty($prDraft['manager_notes'])) {
            $description .= "\n\n**Catatan Manager:**\n" . $prDraft['manager_notes'];
        }

        $items = [];
        foreach ($prDraft['suggested_items'] ?? [] as $item) {
            $catalogueId = $item['catalogue_id'] ?? null;

            // Jika tidak ada catalogue_id (misal item baru dari AI), kita cari atau buat item katalog
            if (!$catalogueId) {
                $catalogue = Catalogue::firstOrCreate(
                    [
                        'name' => $item['name'] ?? 'Item Pengadaan',
                    ],
                    [
                        'company_id' => $buyerCompany->id,
                        'item_code' => $item['item_code'] ?? ('PR-ITEM-' . strtoupper(substr(md5(uniqid()), 0, 6))),
                        'category' => $item['category'] ?? 'General Procurement',
                        'brand' => $item['brand'] ?? 'Universal',
                        'specifications' => $item['detailed_specs'] ?? ($item['name'] ?? ''),
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

        $durationDays = (int) ($prDraft['duration_days'] ?? 7);
        $deliveryPoint = $prDraft['delivery_point_recommendation'] ?? ($buyerCompany->address ?? 'Main Office');
        $department = $prDraft['department'] ?? 'Procurement';

        return $this->createRfqAction->execute(
            buyerCompany: $buyerCompany,
            title: $title,
            description: $description,
            cartItems: $items,
            userId: $userId,
            status: 'pending_approval',
            durationDays: $durationDays,
            documentPath: null,
            deliveryPoint: $deliveryPoint,
            department: $department
        );
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
            'estimated_price' => 0,   // Akan di-override oleh rankSearchProducts atau enrichPrItems
            'ai_score' => 85,
            'ai_match' => true,
        ];
    }

    private function parsePrice($val): float
    {
        if (is_numeric($val)) {
            return (float) $val;
        }
        if (is_string($val)) {
            $cleaned = preg_replace('/[^0-9]/', '', $val);
            return !empty($cleaned) ? (float) $cleaned : 0;
        }
        return 0;
    }

    private function enrichPrItems(array $suggestedItems, array $catalogues, array $intent = [], array $webSearchResults = []): array
    {
        $catalogueMap = collect($catalogues)->keyBy('id');
        $targetItems  = collect($intent['target_items'] ?? []);
        $totalBudget  = $this->parsePrice($intent['estimated_total_budget_idr'] ?? 0);
        $itemCount    = count($suggestedItems) ?: 1;

        // Bangun indeks harga Brave dari webSearchResults yang sudah dikumpulkan
        // Dua key per item: (1) dari item_name fullSearchName, (2) dari key sederhana
        $webPriceIndex = [];
        foreach ($webSearchResults as $key => $data) {
            if ($key === '__general__') continue;

            $wp = $data['web_prices'] ?? [];
            $avgPrice = (float) ($wp['avg_price'] ?? 0);

            // Jika avg_price tidak tersedia, hitung dari individual raw_results yang punya harga
            if ($avgPrice <= 0 && !empty($data['results'])) {
                $rawPrices = collect($data['results'])
                    ->pluck('price')
                    ->filter(fn($p) => (float) $p > 0)
                    ->map(fn($p) => (float) $p);

                if ($rawPrices->isNotEmpty()) {
                    $avgPrice = $rawPrices->average();
                    $wp = [
                        'avg_price' => $avgPrice,
                        'min_price' => $rawPrices->min(),
                        'max_price' => $rawPrices->max(),
                    ];
                }
            }

            if ($avgPrice > 0) {
                $entry = [
                    'avg'   => $avgPrice,
                    'min'   => (float) ($wp['min_price'] ?? $avgPrice),
                    'max'   => (float) ($wp['max_price'] ?? $avgPrice),
                    'count' => count($wp['sources'] ?? ($data['results'] ?? [])),
                ];
                // Index by full item_name
                $webPriceIndex[strtolower(trim($data['item_name'] ?? $key))] = $entry;
                // Index by short key as fallback
                if (strtolower(trim($key)) !== strtolower(trim($data['item_name'] ?? $key))) {
                    $webPriceIndex[strtolower(trim($key))] = $entry;
                }
            }
        }

        $webSearch = $this->webSearch;

        return array_map(function ($item) use ($catalogueMap, $targetItems, $totalBudget, $itemCount, $webPriceIndex, $webSearch) {
            $catId = $item['catalogue_id'] ?? null;
            $cat   = $catId ? $catalogueMap->get($catId) : null;

            // AI selalu mengembalikan 0 — mulai dari sini
            $price       = 0;
            $priceStatus = 'rfq_required';
            $priceNote   = null;

            // Layer 1: Harga historis PO Huntr — disuntikkan via price_status 'historical_reference' dari generatePrDraft
            $aiPrice = $this->parsePrice($item['estimated_price'] ?? 0);
            if ($aiPrice > 0 && ($item['price_status'] ?? '') === 'historical_reference') {
                $price       = $aiPrice;
                $priceStatus = 'historical_reference';
                $priceNote   = $item['price_note'] ?? null;
            }

            // Layer 2: Brave Search index dari Step 3 web search
            if ($price <= 0 && !empty($webPriceIndex)) {
                $itemNameKey = strtolower(trim($item['name'] ?? ''));
                foreach ($webPriceIndex as $webKey => $wp) {
                    // Match fleksibel: substring atau overlap kata kunci
                    $isMatch = str_contains($itemNameKey, $webKey)
                        || str_contains($webKey, $itemNameKey)
                        || (strlen($itemNameKey) >= 4 && str_contains($webKey, substr($itemNameKey, 0, min(8, strlen($itemNameKey)))))
                        || (strlen($webKey) >= 4 && str_contains($itemNameKey, substr($webKey, 0, min(8, strlen($webKey)))));

                    if ($isMatch) {
                        $price       = $wp['avg'];
                        $priceStatus = 'web_market_reference';
                        $srcCount    = $wp['count'];
                        $minFmt      = number_format($wp['min'], 0, ',', '.');
                        $maxFmt      = number_format($wp['max'], 0, ',', '.');
                        $priceNote   = "Referensi Brave Search: Rp {$minFmt} – Rp {$maxFmt} dari {$srcCount} sumber web";
                        break;
                    }
                }
            }

            // Layer 2b: Brave Search on-demand — cari langsung jika belum ada harga
            if ($price <= 0 && $webSearch->isEnabled()) {
                try {
                    $itemNameSearch = trim($item['name'] ?? '');
                    $brandSearch    = trim($item['brand'] ?? '');
                    $liveData = $webSearch->searchMarketPrice($itemNameSearch, $brandSearch);
                    $liveAvg  = (float) ($liveData['avg_price'] ?? 0);

                    // Jika avg_price masih 0, scan individual results dengan filter outlier IQR
                    if ($liveAvg <= 0 && !empty($liveData['raw_results'])) {
                        $rawPrices = collect($liveData['raw_results'])
                            ->pluck('price')
                            ->filter(fn($p) => (float) $p > 0)
                            ->map(fn($p) => (float) $p)
                            ->sort()
                            ->values();

                        if ($rawPrices->count() >= 3) {
                            // Filter outlier: buang harga di luar Q1 - 1.5×IQR ... Q3 + 1.5×IQR
                            $q1  = $rawPrices->get((int) floor(($rawPrices->count() - 1) * 0.25));
                            $q3  = $rawPrices->get((int) floor(($rawPrices->count() - 1) * 0.75));
                            $iqr = $q3 - $q1;
                            $lo  = $q1 - 1.5 * $iqr;
                            $hi  = $q3 + 1.5 * $iqr;
                            $filtered = $rawPrices->filter(fn($p) => $p >= $lo && $p <= $hi);
                            $liveAvg  = $filtered->isNotEmpty() ? $filtered->average() : $rawPrices->average();
                        } elseif ($rawPrices->isNotEmpty()) {
                            $liveAvg = $rawPrices->average();
                        }
                    }

                    if ($liveAvg > 0) {
                        $price       = round($liveAvg);
                        $priceStatus = 'web_market_reference';
                        $liveMin     = (float) ($liveData['min_price'] ?? $liveAvg);
                        $liveMax     = (float) ($liveData['max_price'] ?? $liveAvg);
                        $liveCount   = count($liveData['sources'] ?? []);
                        $minFmt      = number_format($liveMin, 0, ',', '.');
                        $maxFmt      = number_format($liveMax, 0, ',', '.');
                        $priceNote   = "Riset Brave Search (on-demand): Rp {$minFmt} – Rp {$maxFmt} dari {$liveCount} sumber web";
                    }
                } catch (\Throwable) {
                    // Brave gagal — lanjut ke layer berikutnya
                }
            }

            // Layer 3: Harga dari katalog (verified_catalogue)
            if ($price <= 0 && $cat && ($cat['estimated_price'] ?? 0) > 0) {
                $price       = (float) $cat['estimated_price'];
                $priceStatus = 'verified_catalogue';
            }

            // Layer 4: budget_hint_idr dari intent (buyer menyebutkan harga per item)
            if ($price <= 0) {
                $matchedTarget = $targetItems->first(
                    fn($t) =>
                        str_contains(strtolower($t['name'] ?? ''), strtolower($item['name'] ?? '')) ||
                        str_contains(strtolower($item['name'] ?? ''), strtolower($t['name'] ?? ''))
                );
                if ($matchedTarget && !empty($matchedTarget['budget_hint_idr'])) {
                    $qty         = max(1, (int) ($item['qty'] ?? 1));
                    $hints       = $this->parsePrice($matchedTarget['budget_hint_idr']);
                    $price       = $qty > 0 ? round($hints / $qty) : $hints;
                    $priceStatus = 'buyer_budget';
                }
            }

            // Layer 5: total budget buyer dibagi rata
            if ($price <= 0 && $totalBudget > 0) {
                $price       = round($totalBudget / $itemCount);
                $priceStatus = 'buyer_budget';
            }

            // Jika masih 0 — rfq_required (harga tidak diketahui, perlu RFQ)
            if ($price <= 0) {
                $price       = 0;
                $priceStatus = 'rfq_required';
            }

            return [
                'catalogue_id'   => $catId ?: ($cat['id'] ?? null),
                'name'           => $item['name'] ?? ($cat['name'] ?? 'Item Pengadaan'),
                'item_code'      => $item['item_code'] ?? ($cat['item_code'] ?? ('REQ-' . strtoupper(substr(md5(uniqid()), 0, 6)))),
                'category'       => $item['category'] ?? ($cat['category'] ?? 'General'),
                'brand'          => $item['brand'] ?? ($cat['brand'] ?? null),
                'detailed_specs' => $item['detailed_specs'] ?? ($cat['specifications'] ?? ''),
                'qty'            => max(1, (int) ($item['qty'] ?? 1)),
                'uom'            => $item['uom'] ?? ($cat['uom'] ?? 'unit'),
                'estimated_price' => $price,
                'price_status'   => $priceStatus,
                'price_note'     => $priceNote ?? $item['price_note'] ?? null,
                'expected_date'  => $item['expected_date'] ?? now()->addDays(14)->toDateString(),
                'reason'         => $item['reason'] ?? 'Sesuai spesifikasi kebutuhan',
                'image_url'      => $cat['image_url'] ?? null,
                'vendor'         => $cat['vendor'] ?? null,
            ];
        }, $suggestedItems);
    }
}
