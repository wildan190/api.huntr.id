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
        private readonly OpenAiService $openAi,
        private readonly CreateRfqAction $createRfqAction
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
            'step' => 'historical_price_lookup',
            'title' => 'Referensi Harga Historis (PO & Tender)',
            'status' => 'completed',
            'total_found' => $historicalCount,
            'summary' => $historicalCount > 0
                ? "Ditemukan {$historicalCount} referensi harga nyata dari transaksi PO & penawaran vendor sebelumnya."
                : 'Tidak ada riwayat transaksi yang cocok di database. AI akan menggunakan estimasi harga pasar wajar.',
            'sources' => array_values(array_map(fn($v) => [
                'name' => $v['item_name'],
                'avg_price' => $v['avg_price'],
                'last_price' => $v['last_price'],
                'source' => $v['source'],
                'samples' => $v['sample_count'],
            ], $historicalPrices)),
        ];

        // Step 3: Cari produk di katalog database
        $foundCatalogues = $this->discoverCatalogues($intent, $options);
        $steps[] = [
            'step' => 'catalogue_discovery',
            'title' => 'Pencarian Katalog Otomatis',
            'status' => 'completed',
            'total_found' => count($foundCatalogues),
            'summary' => count($foundCatalogues) > 0
                ? 'Ditemukan ' . count($foundCatalogues) . ' produk katalog yang relevan dengan spesifikasi.'
                : 'Tidak ada produk langsung di database, AI menggenerasi spesifikasi item standar industri.',
        ];

        // Step 4: Evaluasi & Komparasi Produk
        $comparison = null;
        if (count($foundCatalogues) >= 2) {
            $candidatesToCompare = array_slice($foundCatalogues, 0, 5);
            $comparison = $this->openAi->compareProducts($candidatesToCompare, $query);
            $steps[] = [
                'step' => 'product_comparison',
                'title' => 'Komparasi & Evaluasi Produk',
                'status' => 'completed',
                'summary' => $comparison['executive_summary'] ?? 'Evaluasi komparasi produk selesai.',
                'winner_id' => $comparison['winner_id'] ?? null,
            ];
        } else {
            $steps[] = [
                'step' => 'product_comparison',
                'title' => 'Evaluasi Produk Tunggal',
                'status' => 'completed',
                'summary' => 'Kandidat produk telah dievaluasi dan siap diproses ke dokumen PR.',
            ];
        }

        // Step 5: Susun Dokumen PR & Deskripsi Lengkap
        $company = !empty($options['company_id']) ? Company::find($options['company_id']) : null;
        $context = [
            'company_name' => $company?->name,
            'address' => $company?->address,
            'department' => $intent['department'] ?? 'Procurement',
            'estimated_total_budget_idr' => $intent['estimated_total_budget_idr'] ?? null,
            'target_items' => $intent['target_items'] ?? [],
        ];

        $prDraft = $this->openAi->generatePrDraft($query, $foundCatalogues, $context, $historicalPrices);

        // Enrich suggested items jika ada mapping katalog
        $enrichedItems = $this->enrichPrItems($prDraft['suggested_items'] ?? [], $foundCatalogues, $intent);
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
            'success' => true,
            'query' => $query,
            'intent' => $intent,
            'catalogues' => $foundCatalogues,
            'comparison' => $comparison,
            'pr_draft' => $prDraft,
            'created_rfq' => $createdRfq ? $createdRfq->load(['items.catalogue']) : null,
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
                return $dbQuery->get()->map(fn($c) => $this->mapCatalogueItem($c))->toArray();
            }

            $keywords = $intent['keywords'] ?? [];
            $category = $intent['category'] ?? null;
            $brand = $intent['brand'] ?? null;

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

            $results = $dbQuery->limit(20)->get();

            // Re-ranking dengan AI jika ada kandidat
            if ($results->isNotEmpty()) {
                $companyId = $options['company_id'] ?? null;
                $ranked = $this->openAi->rankSearchProducts($intent['ai_summary'] ?? '', $results->toArray(), $companyId);
                $rankedById = collect($ranked)->keyBy('product_id');

                $mapped = $results->map(function ($p) use ($rankedById) {
                    $item = $this->mapCatalogueItem($p);
                    $rankInfo = $rankedById->get($p->id);
                    if ($rankInfo) {
                        $aiMatch = $rankInfo['is_match'] ?? true;
                        $item['ai_match'] = $aiMatch;
                        $item['ai_score'] = (int) ($rankInfo['relevance_score'] ?? 75);
                        $item['fit_reason'] = $rankInfo['fit_reason'] ?? null;
                        // Override harga HANYA jika AI memberikan harga > 0
                        if (!empty($rankInfo['estimated_unit_price_idr']) && $rankInfo['estimated_unit_price_idr'] > 0) {
                            $item['estimated_price'] = (float) $rankInfo['estimated_unit_price_idr'];
                        }
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

    private function enrichPrItems(array $suggestedItems, array $catalogues, array $intent = []): array
    {
        $catalogueMap = collect($catalogues)->keyBy('id');
        $targetItems = collect($intent['target_items'] ?? []);
        $totalBudget = $this->parsePrice($intent['estimated_total_budget_idr'] ?? 0);
        $itemCount = count($suggestedItems) ?: 1;

        return array_map(function ($item) use ($catalogueMap, $targetItems, $totalBudget, $itemCount) {
            $catId = $item['catalogue_id'] ?? null;
            $cat = $catId ? $catalogueMap->get($catId) : null;

            // Layer 1: harga dari AI (suggested_items[].estimated_price)
            $price = $this->parsePrice($item['estimated_price'] ?? 0);
            $priceStatus = $item['price_status'] ?? 'rfq_required';

            // Layer 2: harga dari katalog yang di-ranking AI (estimated_price dari rankSearchProducts)
            if ($price <= 0 && $cat && ($cat['estimated_price'] ?? 0) > 0) {
                $price = (float) $cat['estimated_price'];
                $priceStatus = 'verified_catalogue';
            }

            // Layer 3: cek target_items budget_hint_idr berdasarkan nama item jika user specify
            if ($price <= 0) {
                $matchedTarget = $targetItems->first(
                    fn($t) =>
                        str_contains(strtolower($t['name'] ?? ''), strtolower($item['name'] ?? '')) ||
                        str_contains(strtolower($item['name'] ?? ''), strtolower($t['name'] ?? ''))
                );
                if ($matchedTarget && !empty($matchedTarget['budget_hint_idr'])) {
                    $qty = max(1, (int) ($item['qty'] ?? 1));
                    $hints = $this->parsePrice($matchedTarget['budget_hint_idr']);
                    $price = $qty > 0 ? round($hints / $qty) : $hints;
                    $priceStatus = 'buyer_budget';
                }
            }

            // Layer 4: bagi rata total budget HANYA jika buyer menyebutkan total anggaran
            if ($price <= 0 && $totalBudget > 0) {
                $price = round($totalBudget / $itemCount);
                $priceStatus = 'buyer_budget';
            }

            // Jika masih 0, status harus rfq_required (Zero Hallucination)
            if ($price <= 0) {
                $price = 0;
                $priceStatus = 'rfq_required';
            } elseif (empty($priceStatus)) {
                $priceStatus = $catId ? 'verified_catalogue' : 'buyer_budget';
            }

            return [
                'catalogue_id' => $catId ?: ($cat['id'] ?? null),
                'name' => $item['name'] ?? ($cat['name'] ?? 'Item Pengadaan'),
                'item_code' => $item['item_code'] ?? ($cat['item_code'] ?? ('REQ-' . strtoupper(substr(md5(uniqid()), 0, 6)))),
                'category' => $item['category'] ?? ($cat['category'] ?? 'General'),
                'brand' => $item['brand'] ?? ($cat['brand'] ?? null),
                'detailed_specs' => $item['detailed_specs'] ?? ($cat['specifications'] ?? ''),
                'qty' => max(1, (int) ($item['qty'] ?? 1)),
                'uom' => $item['uom'] ?? ($cat['uom'] ?? 'unit'),
                'estimated_price' => $price,
                'price_status' => $priceStatus,
                'expected_date' => $item['expected_date'] ?? now()->addDays(14)->toDateString(),
                'reason' => $item['reason'] ?? 'Sesuai spesifikasi kebutuhan',
                'image_url' => $cat['image_url'] ?? null,
                'vendor' => $cat['vendor'] ?? null,
            ];
        }, $suggestedItems);
    }
}
