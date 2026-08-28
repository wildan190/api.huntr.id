<?php

namespace App\Domain\AI\Services;

use App\Domain\Catalogue\Models\Catalogue;
use App\Domain\Company\Models\Company;
use App\Domain\Rfq\Actions\CreateRfqAction;
use App\Domain\Rfq\Models\Rfq;
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
    ) {}

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
            'step'        => 'intent_analysis',
            'title'       => 'Analisis Kebutuhan & Spesifikasi',
            'status'      => 'completed',
            'summary'     => $intent['ai_summary'] ?? 'Analisis kebutuhan selesai.',
            'target_items'=> $intent['target_items'] ?? [],
        ];

        // Step 2: Cari produk di katalog database
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

        // Step 3: Evaluasi & Komparasi Produk
        $comparison = null;
        if (count($foundCatalogues) >= 2) {
            $candidatesToCompare = array_slice($foundCatalogues, 0, 5);
            $comparison = $this->openAi->compareProducts($candidatesToCompare, $query);
            $steps[] = [
                'step'        => 'product_comparison',
                'title'       => 'Komparasi & Evaluasi Produk',
                'status'      => 'completed',
                'summary'     => $comparison['executive_summary'] ?? 'Evaluasi komparasi produk selesai.',
                'winner_id'   => $comparison['winner_id'] ?? null,
            ];
        } else {
            $steps[] = [
                'step'        => 'product_comparison',
                'title'       => 'Evaluasi Produk Tunggal',
                'status'      => 'completed',
                'summary'     => 'Kandidat produk telah dievaluasi dan siap diproses ke dokumen PR.',
            ];
        }

        // Step 4: Susun Dokumen PR & Deskripsi Lengkap
        $company = !empty($options['company_id']) ? Company::find($options['company_id']) : null;
        $context = [
            'company_name'               => $company?->name,
            'address'                    => $company?->address,
            'department'                 => $intent['department'] ?? 'Procurement',
            'estimated_total_budget_idr' => $intent['estimated_total_budget_idr'] ?? null,
            'target_items'               => $intent['target_items'] ?? [],
        ];

        $prDraft = $this->openAi->generatePrDraft($query, $foundCatalogues, $context);
        
        // Enrich suggested items jika ada mapping katalog
        $enrichedItems = $this->enrichPrItems($prDraft['suggested_items'] ?? [], $foundCatalogues, $intent);
        $prDraft['suggested_items'] = $enrichedItems;

        // Recalculate total budget
        $calculatedTotal = collect($enrichedItems)->sum(fn($i) => ($i['qty'] ?? 1) * ($i['estimated_price'] ?? 0));
        $prDraft['estimated_total_budget'] = $calculatedTotal > 0 
            ? $calculatedTotal 
            : $this->parsePrice($prDraft['estimated_total_budget'] ?? ($intent['estimated_total_budget_idr'] ?? 0));

        $steps[] = [
            'step'        => 'pr_formulation',
            'title'       => 'Penyusunan Purchase Requisition (PR)',
            'status'      => 'completed',
            'summary'     => 'Draft PR resmi dengan deskripsi detail, justifikasi, dan rincian item telah selesai disusun.',
            'pr_title'    => $prDraft['title'] ?? '',
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
                    'step'        => 'pr_creation',
                    'title'       => 'Pembuatan PR ke Sistem Huntr',
                    'status'      => 'completed',
                    'rfq_id'      => $createdRfq->id,
                    'summary'     => "PR berhasil dibuat dengan nomor ID: {$createdRfq->id}",
                ];
            } catch (\Exception $e) {
                Log::error('AgenticProcurement: Auto create PR failed', ['error' => $e->getMessage()]);
                $steps[] = [
                    'step'        => 'pr_creation',
                    'title'       => 'Pembuatan PR ke Sistem Huntr',
                    'status'      => 'failed',
                    'summary'     => 'Gagal menyimpan PR otomatis: ' . $e->getMessage(),
                ];
            }
        }

        return [
            'success'          => true,
            'query'            => $query,
            'intent'           => $intent,
            'catalogues'       => $foundCatalogues,
            'comparison'       => $comparison,
            'pr_draft'         => $prDraft,
            'created_rfq'      => $createdRfq ? $createdRfq->load(['items.catalogue']) : null,
            'workflow_steps'   => $steps,
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
            'success'                 => true,
            'reply'                   => $reply,
            'has_procurement_intent'  => $hasProcurementIntent,
        ];
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
                        $item['ai_match']  = $aiMatch;
                        $item['ai_score']  = (int) ($rankInfo['relevance_score'] ?? 75);
                        $item['fit_reason']= $rankInfo['fit_reason'] ?? null;
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
                        'company_id'     => $buyerCompany->id,
                        'item_code'      => $item['item_code'] ?? ('PR-ITEM-' . strtoupper(substr(md5(uniqid()), 0, 6))),
                        'category'       => $item['category'] ?? 'General Procurement',
                        'brand'          => $item['brand'] ?? 'Universal',
                        'specifications' => $item['detailed_specs'] ?? ($item['name'] ?? ''),
                        'uom'            => $item['uom'] ?? 'unit',
                    ]
                );
                $catalogueId = $catalogue->id;
            }

            $items[] = [
                'catalogue_id'    => $catalogueId,
                'qty'             => max(1, (int) ($item['qty'] ?? 1)),
                'estimated_price' => (float) ($item['estimated_price'] ?? 0),
                'expected_date'   => $item['expected_date'] ?? now()->addDays(14)->toDateString(),
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
            'id'              => $c->id,
            'name'            => $c->name,
            'item_code'       => $c->item_code,
            'category'        => $c->category,
            'brand'           => $c->brand,
            'specifications'  => $c->specifications,
            'uom'             => $c->uom ?? 'unit',
            'image_path'      => $c->image_path,
            'image_url'       => $c->image_url,
            'vendor'          => $c->company?->name,
            'company_id'      => $c->company_id,
            'estimated_price' => 0,   // Akan di-override oleh rankSearchProducts atau enrichPrItems
            'ai_score'        => 85,
            'ai_match'        => true,
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
        $targetItems  = collect($intent['target_items'] ?? []);
        $totalBudget  = $intent['estimated_total_budget_idr'] ?? 0;
        $itemCount    = count($suggestedItems) ?: 1;

        return array_map(function ($item) use ($catalogueMap, $targetItems, $totalBudget, $itemCount) {
            $catId = $item['catalogue_id'] ?? null;
            $cat   = $catId ? $catalogueMap->get($catId) : null;

            // Layer 1: harga dari AI (suggested_items[].estimated_price)
            $price = $this->parsePrice($item['estimated_price'] ?? 0);

            // Layer 2: harga dari katalog yang di-ranking AI (estimated_price dari rankSearchProducts)
            if ($price <= 0 && $cat && ($cat['estimated_price'] ?? 0) > 0) {
                $price = (float) $cat['estimated_price'];
            }

            // Layer 3: cek target_items budget_hint_idr berdasarkan nama item
            if ($price <= 0) {
                $matchedTarget = $targetItems->first(fn($t) =>
                    str_contains(strtolower($t['name'] ?? ''), strtolower($item['name'] ?? '')) ||
                    str_contains(strtolower($item['name'] ?? ''), strtolower($t['name'] ?? ''))
                );
                if ($matchedTarget && !empty($matchedTarget['budget_hint_idr'])) {
                    $qty   = max(1, (int) ($item['qty'] ?? 1));
                    $hints = $this->parsePrice($matchedTarget['budget_hint_idr']);
                    // budget_hint_idr biasanya total untuk kuantitas itu
                    $price = $qty > 0 ? round($hints / $qty) : $hints;
                }
            }

            // Layer 4: bagi rata total budget jika masih 0
            if ($price <= 0 && $totalBudget > 0) {
                $price = round($totalBudget / $itemCount);
            }

            return [
                'catalogue_id'    => $catId ?: ($cat['id'] ?? null),
                'name'            => $item['name'] ?? ($cat['name'] ?? 'Item Pengadaan'),
                'item_code'       => $item['item_code'] ?? ($cat['item_code'] ?? null),
                'category'        => $item['category'] ?? ($cat['category'] ?? 'General'),
                'brand'           => $item['brand'] ?? ($cat['brand'] ?? null),
                'detailed_specs'  => $item['detailed_specs'] ?? ($cat['specifications'] ?? ''),
                'qty'             => max(1, (int) ($item['qty'] ?? 1)),
                'uom'             => $item['uom'] ?? ($cat['uom'] ?? 'unit'),
                'estimated_price' => $price,
                'expected_date'   => $item['expected_date'] ?? now()->addDays(14)->toDateString(),
                'reason'          => $item['reason'] ?? 'Sesuai spesifikasi kebutuhan',
                'image_url'       => $cat['image_url'] ?? null,
                'vendor'          => $cat['vendor'] ?? null,
            ];
        }, $suggestedItems);
    }
}
