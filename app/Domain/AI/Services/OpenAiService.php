<?php

namespace App\Domain\AI\Services;

use App\Domain\AI\Models\AiUsageLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * OpenAiService
 *
 * Wrapper untuk OpenAI ChatGPT API (gpt-4o-mini / gpt-4o).
 * Bertanggung jawab untuk semua komunikasi AI & Agentic Procurement di platform Huntr.
 * Setiap call ke API dicatat di ai_usage_logs untuk tracking quota & billing.
 */
class OpenAiService
{
    private string $apiKey;
    private string $model;
    private int $timeout;

    public function __construct()
    {
        $rawKey = config('ai.openai_api_key') ?: env('OPENAI_API_KEY');
        $this->apiKey = is_string($rawKey) ? $rawKey : '';
        $rawModel = config('ai.openai_model') ?: env('OPENAI_MODEL');
        $this->model  = is_string($rawModel) ? $rawModel : 'gpt-4o-mini';
        $this->timeout = (int) config('ai.timeout', 45);
    }

    /**
     * Ekstrak estimasi budget dari teks Bahasa Indonesia / format mata uang.
     */
    public function extractBudgetFromText(string $text): ?float
    {
        $text = strtolower($text);

        // "400 juta", "400jt", "400 jt", "400.5 juta"
        if (preg_match('/([\d\.\,]+)\s*(?:juta|jt)\b/i', $text, $matches)) {
            $raw = str_replace(',', '.', $matches[1]);
            return ((float) $raw) * 1000000;
        }

        // "1.5 miliar", "2 milyar", "1.5 M", "1.5m"
        if (preg_match('/([\d\.\,]+)\s*(?:miliar|milyar|m)\b/i', $text, $matches)) {
            $raw = str_replace(',', '.', $matches[1]);
            return ((float) $raw) * 1000000000;
        }

        // "500 ribu", "500rb", "500k"
        if (preg_match('/([\d\.\,]+)\s*(?:ribu|rb|k)\b/i', $text, $matches)) {
            $raw = str_replace(',', '.', $matches[1]);
            return ((float) $raw) * 1000;
        }

        // "Rp 400.000.000"
        if (preg_match('/(?:rp\.?|idr)\s*([\d\.\,]+)/i', $text, $matches)) {
            $cleaned = preg_replace('/[^0-9]/', '', $matches[1]);
            if (!empty($cleaned) && (float)$cleaned > 1000) {
                return (float) $cleaned;
            }
        }

        // Plain budget number e.g. "budget 400000000"
        if (preg_match('/(?:budget|anggaran|dana|biaya)\s*(?:sekitar|sebesar|maksimal|maks|min)?\s*([\d\.\,]+)/i', $text, $matches)) {
            $cleaned = preg_replace('/[^0-9]/', '', $matches[1]);
            if (!empty($cleaned) && (float)$cleaned > 1000) {
                return (float) $cleaned;
            }
        }

        return null;
    }

    /**
     * Ekstrak items & kuantitas dari teks natural language.
     */
    public function extractItemsFromText(string $text, ?float $totalBudget = null): array
    {
        $cleanText = preg_replace('/(saya|kami)?\s*(butuh|perlu|ingin|pengadaan|mencari)\s+/i', '', $text);
        $cleanText = preg_replace('/(dengan|target|sekitar)?\s*budget.*/i', '', $cleanText);
        $parts = preg_split('/\s+(?:dan|\&|\+|,)\s+/i', $cleanText);
        $items = [];
        
        foreach ($parts as $part) {
            $part = trim($part);
            if (empty($part) || strlen($part) < 3) continue;
            $qty = 1;
            if (preg_match('/(\d+)\s*(?:unit|pcs|set|buah|kotak|box|paket|pasang)?\s+(.*)/i', $part, $m)) {
                $qty = (int) $m[1];
                $name = trim($m[2]);
            } else {
                $name = $part;
            }
            $items[] = [
                'name'           => ucfirst($name),
                'qty'            => max(1, $qty),
                'uom'            => 'unit',
                'detailed_specs' => ucfirst($name) . ' (Standar Enterprise)',
            ];
        }

        $count = count($items);
        if ($count > 0 && $totalBudget && $totalBudget > 0) {
            $allocatedPerItemTotal = $totalBudget / $count;
            foreach ($items as &$it) {
                $it['estimated_price'] = round($allocatedPerItemTotal / $it['qty']);
            }
        } else {
            foreach ($items as &$it) {
                $it['estimated_price'] = 15000000;
            }
        }

        return $items;
    }

    /**
     * Kirim prompt ke OpenAI dan dapatkan teks response.
     * @param string|null $companyId Untuk tracking usage log per perusahaan
     * @param string|null $endpoint  Nama endpoint/fitur yang memanggil (untuk audit)
     */
    public function ask(string $prompt, string $systemInstruction = '', ?string $companyId = null, string $endpoint = 'ask'): string
    {
        if (empty($this->apiKey)) {
            Log::warning('OpenAiService: OpenAI API key is missing.');
            throw new \RuntimeException('OpenAI API Key belum terkonfigurasi.');
        }

        $messages = [];
        if (!empty($systemInstruction)) {
            $messages[] = ['role' => 'system', 'content' => $systemInstruction];
        }
        $messages[] = ['role' => 'user', 'content' => $prompt];

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type'  => 'application/json',
            ])
            ->timeout($this->timeout)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model'       => $this->model,
                'messages'    => $messages,
                'temperature' => 0.4,
            ]);

            if ($response->failed()) {
                Log::error('OpenAiService API error', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
                throw new \RuntimeException('OpenAI API Error: ' . $response->status() . ' - ' . $response->body());
            }

            $data = $response->json();

            // Track usage setiap call berhasil
            $this->trackUsage($data['usage'] ?? [], $endpoint, $companyId);

            return $data['choices'][0]['message']['content'] ?? '';

        } catch (\Exception $e) {
            Log::error('OpenAiService Exception', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Kirim percakapan multi-turn ke OpenAI.
     * @param string|null $companyId Untuk tracking usage log per perusahaan
     * @param string|null $endpoint  Nama endpoint/fitur yang memanggil
     */
    public function chat(array $messages, string $systemInstruction = '', ?string $companyId = null, string $endpoint = 'chat'): string
    {
        if (empty($this->apiKey)) {
            Log::warning('OpenAiService: OpenAI API key is missing.');
            throw new \RuntimeException('OpenAI API Key belum terkonfigurasi.');
        }

        $allMessages = [];
        if (!empty($systemInstruction)) {
            $allMessages[] = ['role' => 'system', 'content' => $systemInstruction];
        }
        foreach ($messages as $msg) {
            $allMessages[] = [
                'role'    => $msg['role'] ?? 'user',
                'content' => $msg['content'] ?? '',
            ];
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type'  => 'application/json',
            ])
            ->timeout($this->timeout)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model'       => $this->model,
                'messages'    => $allMessages,
                'temperature' => 0.4,
            ]);

            if ($response->failed()) {
                Log::error('OpenAiService chat API error', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
                throw new \RuntimeException('OpenAI API Error: ' . $response->status());
            }

            $data = $response->json();

            // Track usage setiap call berhasil
            $this->trackUsage($data['usage'] ?? [], $endpoint, $companyId);

            return $data['choices'][0]['message']['content'] ?? '';

        } catch (\Exception $e) {
            Log::error('OpenAiService Chat Exception', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Kirim prompt dan minta JSON response dari OpenAI.
     * @param string|null $companyId Untuk tracking usage log per perusahaan
     * @param string|null $endpoint  Nama endpoint/fitur yang memanggil
     */
    public function askJson(string $prompt, string $systemInstruction = '', ?string $companyId = null, string $endpoint = 'askJson'): array
    {
        $jsonPrompt = $prompt . "\n\nPenting: Balas HANYA dengan JSON valid, tanpa markdown format ```json, tanpa teks pengantar atau penutup.";
        $rawResponse = $this->ask($jsonPrompt, $systemInstruction, $companyId, $endpoint);

        $cleaned = preg_replace('/^```(?:json)?\s*/m', '', $rawResponse);
        $cleaned = preg_replace('/\s*```$/m', '', $cleaned);
        $cleaned = trim($cleaned);

        $decoded = json_decode($cleaned, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            preg_match('/[\{\[].*[\}\]]/s', $cleaned, $matches);
            if (!empty($matches[0])) {
                $decoded = json_decode($matches[0], true);
            }
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Ekstrak kebutuhan pengadaan & intent pencarian dari natural language prompt.
     */
    public function extractSearchIntent(string $userQuery): array
    {
        $prompt = <<<PROMPT
Analisis permintaan kebutuhan procurement B2B berikut dan ekstrak parameter pencarian dan kriteria spesifikasi.

Permintaan user: "{$userQuery}"

Balas dalam format JSON:
{
  "keywords": ["kata kunci produk/merk 1", "kata kunci 2"],
  "category": "kategori produk (misal: Electronics, IT Hardware, Office Supplies, Industrial, Safety, Machinery)",
  "brand": "merk spesifik yang diminta atau null",
  "target_items": [
    {
      "name": "nama umum item (misal: Laptop Engineering)",
      "spec_requirements": "ringkasan spesifikasi yang diminta (misal: Core i7/Ryzen 7, 32GB RAM, 1TB SSD)",
      "quantity": 10,
      "uom": "unit",
      "budget_hint_idr": 25000000
    }
  ],
  "estimated_total_budget_idr": 250000000,
  "department": "Departemen yang cocok (misal: IT & Engineering, General Affairs, Operations, HR)",
  "urgency": "Normal / Urgent / Critical",
  "ai_summary": "Rangkuman 1 kalimat jelas tentang kebutuhan procurement ini",
  "is_comparison": true/false
}
PROMPT;

        try {
            $result = $this->askJson($prompt, 'Kamu adalah AI Procurement Specialist yang ahli menganalisis kebutuhan pengadaan barang/jasa perusahaan.', null, 'extractSearchIntent');
            if (!empty($result) && is_array($result)) {
                if (empty($result['estimated_total_budget_idr'])) {
                    $result['estimated_total_budget_idr'] = $this->extractBudgetFromText($userQuery);
                }
                return $result;
            }
        } catch (\Exception $e) {
            Log::warning('OpenAiService: extractSearchIntent fallback', ['error' => $e->getMessage()]);
        }

        // Resilient Fallback Heuristic
        $detectedBudget = $this->extractBudgetFromText($userQuery);
        $detectedItems = $this->extractItemsFromText($userQuery, $detectedBudget);

        return [
            'keywords'                   => array_filter(explode(' ', preg_replace('/[^a-zA-Z0-9\s]/', ' ', $userQuery)), fn($w) => strlen($w) > 2),
            'category'                   => 'IT & Office Equipment',
            'brand'                      => null,
            'target_items'               => array_map(fn($it) => [
                'name'              => $it['name'],
                'spec_requirements'=> $it['detailed_specs'],
                'quantity'          => $it['qty'],
                'uom'               => $it['uom'],
                'budget_hint_idr'   => $it['estimated_price'],
            ], $detectedItems),
            'estimated_total_budget_idr' => $detectedBudget ?: ($detectedItems ? collect($detectedItems)->sum(fn($i) => $i['qty'] * $i['estimated_price']) : 250000000),
            'department'                 => 'Information Technology & Procurement',
            'urgency'                    => 'Normal',
            'ai_summary'                 => 'Pengadaan ' . implode(', ', array_column($detectedItems, 'name')),
            'is_comparison'              => count($detectedItems) >= 2 || str_contains(strtolower($userQuery), 'bandingkan') || str_contains(strtolower($userQuery), 'compare'),
        ];
    }

    /**
     * Re-rank & nilai kesesuaian produk katalog terhadap kebutuhan procurement.
     * @param string|null $companyId Untuk tracking usage log per perusahaan
     */
    public function rankSearchProducts(string $userQuery, array $products, ?string $companyId = null): array
    {
        if (empty($products)) {
            return [];
        }

        $candidates = collect($products)->map(fn($p) => [
            'id'             => $p['id'],
            'name'           => $p['name'],
            'category'       => $p['category'] ?? null,
            'brand'          => $p['brand'] ?? null,
            'specifications' => $p['specifications'] ?? null,
            'uom'            => $p['uom'] ?? 'unit',
            'vendor'         => $p['company']['name'] ?? ($p['vendor'] ?? null),
        ])->toArray();

        $candidatesJson = json_encode($candidates, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $prompt = <<<PROMPT
Kebutuhan User: "{$userQuery}"

Daftar Produk Katalog yang Ditemukan:
{$candidatesJson}

Evaluasi kesesuaian setiap produk dengan kebutuhan pengadaan user.
Balas dengan format JSON:
{
  "results": [
    {
      "product_id": "id produk",
      "is_match": true,
      "relevance_score": 92,
      "fit_reason": "Alasan singkat mengapa produk ini cocok atau kurang cocok",
      "suggested_qty": 1,
      "estimated_unit_price_idr": 15000000
    }
  ]
}
PROMPT;

        try {
            $response = $this->askJson($prompt, 'Kamu adalah AI Technical Procurement Evaluator.', $companyId, 'rankSearchProducts');
            if (!empty($response['results'])) {
                return $response['results'];
            }
        } catch (\Exception $e) {
            Log::warning('OpenAiService: rankSearchProducts fallback', ['error' => $e->getMessage()]);
        }

        // Fallback rankings — beri score berbeda per posisi agar sorting tetap deterministik
        return array_map(function ($p, $idx) {
            return [
                'product_id'               => $p['id'],
                'is_match'                 => true,
                'relevance_score'          => max(50, 90 - ($idx * 5)), // 90, 85, 80, 75...
                'fit_reason'               => 'Katalog sesuai dengan kriteria kategori.',
                'suggested_qty'            => 1,
                'estimated_unit_price_idr' => 0, // Biarkan 0, enrichPrItems akan handle
            ];
        }, $candidates, array_keys($candidates));
    }

    /**
     * Bandingkan beberapa produk katalog secara objektif dan mendalam.
     */
    public function compareProducts(array $catalogues, ?string $userNeed = null): array
    {
        $cataloguesJson = json_encode($catalogues, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $userNeedPrompt = $userNeed ? "Kebutuhan Khusus Buyer: \"{$userNeed}\"" : "Bandingkan untuk kebutuhan pengadaan standar enterprise.";

        $prompt = <<<PROMPT
{$userNeedPrompt}

Daftar Produk untuk Dibandingkan:
{$cataloguesJson}

Lakukan perbandingan komprehensif dari sudut pandang procurement B2B.
Balas dengan format JSON:
{
  "comparison_matrix": [
    {
      "catalogue_id": "id",
      "product_name": "nama produk",
      "vendor_name": "nama vendor",
      "score": 88,
      "key_specs": "ringkasan spesifikasi utama",
      "pros": ["kelebihan 1", "kelebihan 2"],
      "cons": ["kekurangan 1"],
      "estimated_price_idr": 18500000,
      "best_for": "cocok untuk use-case apa",
      "value_rating": "Sangat Baik / Baik / Cukup"
    }
  ],
  "winner_id": "catalogue_id produk terbaik yang direkomendasikan",
  "winner_reason": "Penjelasan mengapa produk ini menjadi pilihan utama",
  "executive_summary": "Ringkasan perbandingan dan rekomendasi keputusan pengadaan",
  "spec_table": [
    {
      "feature": "Fitur / Parameter",
      "values": {
        "nama_produk_1": "nilai spek 1",
        "nama_produk_2": "nilai spek 2"
      }
    }
  ]
}
PROMPT;

        try {
            $res = $this->askJson($prompt, 'Kamu adalah Procurement Consultant & Hardware/Product Specialist senior.', null, 'compareProducts');
            if (!empty($res['comparison_matrix'])) {
                return $res;
            }
        } catch (\Exception $e) {
            Log::error('OpenAiService: compareProducts fallback', ['error' => $e->getMessage()]);
        }

        // Fallback comparison
        $matrix = [];
        foreach ($catalogues as $idx => $c) {
            $matrix[] = [
                'catalogue_id'        => $c['id'] ?? ("cat-{$idx}"),
                'product_name'        => $c['name'] ?? 'Produk Katalog',
                'vendor_name'         => $c['vendor'] ?? 'Vendor Resmi',
                'score'               => 85 + (5 - $idx),
                'key_specs'           => $c['specifications'] ?? ($c['name'] ?? ''),
                'pros'                => ['Spesifikasi terstandarisasi', 'Dukungan garansi resmi'],
                'cons'                => ['Waktu tunggu pengiriman standar'],
                'estimated_price_idr' => 15000000,
                'best_for'            => 'Kebutuhan tim profesional & operasional',
                'value_rating'        => 'Sangat Baik',
            ];
        }

        return [
            'comparison_matrix' => $matrix,
            'winner_id'         => $catalogues[0]['id'] ?? null,
            'winner_reason'     => ($catalogues[0]['name'] ?? 'Opsi pertama') . ' memiliki spesifikasi paling seimbang dan keandalan vendor tinggi.',
            'executive_summary' => 'Evaluasi komparasi produk telah dilakukan berdasarkan spesifikasi teknis dan efisiensi biaya.',
            'spec_table'        => [],
        ];
    }

    /**
     * Generate PR Draft komprehensif dengan deskripsi profesional, justifikasi bisnis, & line items.
     */
    public function generatePrDraft(string $userPrompt, array $matchedItems, array $context = []): array
    {
        $itemsJson = json_encode($matchedItems, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $contextJson = json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $intentBudget = $context['estimated_total_budget_idr'] ?? $this->extractBudgetFromText($userPrompt);
        $budgetInstruction = $intentBudget 
            ? "PENTING: User menyebutkan target total budget sekitar Rp " . number_format((float)$intentBudget, 0, ',', '.') . ". Pastikan 'estimated_total_budget' bernilai persis atau mendekati angka tersebut (misal: " . (int)$intentBudget . "), dan berikan 'estimated_price' satuan untuk setiap item yang realistis sehingga (qty * estimated_price) totalnya mendekati angka tersebut."
            : "PENTING: 'estimated_price' (harga satuan) dan 'estimated_total_budget' (total anggaran) WAJIB DIISI ANGKA INTEGER REALISTIS DALAM RUPIAH (contoh: 20000000, 350000000, JANGAN 0, tanpa titik atau Rp).";

        $prompt = <<<PROMPT
Permintaan Kebutuhan Pengadaan User:
"{$userPrompt}"

Konteks Tambahan:
{$contextJson}

{$budgetInstruction}

Produk Terpilih / Katalog Tersedia:
{$itemsJson}

Buat draft Purchase Requisition (PR) resmi perusahaan yang sangat lengkap, terstruktur, dan profesional dalam Bahasa Indonesia formal.
Setiap item HARUS memiliki estimasi harga satuan wajar ('estimated_price') dalam Rupiah (integer), dan 'estimated_total_budget' adalah total akumulasi semua item.

Balas HANYA dengan JSON valid format:
{
  "title": "Judul PR Resmi (misal: PR-2026-IT: Pengadaan Laptop High Performance & Monitor)",
  "department": "Nama Departemen Pengaju (misal: Information Technology / Operations / General Affairs)",
  "description": "Deskripsi lengkap PR: latar belakang kebutuhan, justifikasi pengadaan, spesifikasi minimum, ruang lingkup, dan SLA garansi (minimal 3-5 kalimat berbobot)",
  "business_justification": "Alasan urgensi bisnis mengapa pengadaan ini perlu disetujui oleh Manager/Finance",
  "duration_days": 7,
  "priority": "Normal / Urgent / Critical",
  "suggested_items": [
    {
      "catalogue_id": "id katalog jika ada",
      "name": "Nama lengkap produk & tipe",
      "item_code": "Kode barang atau SKU",
      "category": "Kategori barang",
      "brand": "Merk barang",
      "detailed_specs": "Rincian spesifikasi teknis lengkap item",
      "qty": 10,
      "uom": "unit / set / pcs / box",
      "estimated_price": 25000000,
      "expected_date": "2026-09-01",
      "reason": "Alasan pemilihan item ini"
    }
  ],
  "estimated_total_budget": 250000000,
  "delivery_point_recommendation": "Rekomendasi alamat/titik pengiriman barang",
  "vendor_evaluation_criteria": [
    "Kesesuaian spesifikasi teknis 100%",
    "Garansi resmi minimal 1 tahun",
    "Lead time pengiriman maksimal 14 hari kerja"
  ],
  "manager_notes": "Catatan ringkas untuk approval manager"
}
PROMPT;

        try {
            $res = $this->askJson($prompt, 'Kamu adalah Chief Procurement Officer (CPO) & Senior Procurement Manager yang membuat dokumen Purchase Requisition berkualitas enterprise dengan kalkulasi anggaran yang akurat.', null, 'generatePrDraft');
            if (!empty($res['suggested_items'])) {
                // Pastikan setiap suggested_item punya catalogue_id yang benar dari matchedItems jika AI tidak isi
                $catalogueById = collect($matchedItems)->keyBy('id');
                $res['suggested_items'] = array_map(function ($item) use ($catalogueById) {
                    if (empty($item['catalogue_id']) || !$catalogueById->has($item['catalogue_id'])) {
                        // Coba match by name
                        $matched = $catalogueById->first(fn($c) =>
                            isset($c['name']) && strtolower(trim($c['name'])) === strtolower(trim($item['name'] ?? ''))
                        );
                        if ($matched) {
                            $item['catalogue_id'] = $matched['id'];
                            // Carry over estimated_price dari AI ranking jika ada di katalog
                            if (empty($item['estimated_price']) || $item['estimated_price'] <= 0) {
                                $item['estimated_price'] = $matched['estimated_price'] ?? 0;
                            }
                        }
                    } else {
                        // catalogue_id valid — carry over harga dari katalog jika AI tidak isi
                        $cat = $catalogueById->get($item['catalogue_id']);
                        if (($item['estimated_price'] ?? 0) <= 0 && isset($cat['estimated_price']) && $cat['estimated_price'] > 0) {
                            $item['estimated_price'] = $cat['estimated_price'];
                        }
                    }
                    return $item;
                }, $res['suggested_items']);
                return $res;
            }
        } catch (\Exception $e) {
            Log::error('OpenAiService: generatePrDraft fallback', ['error' => $e->getMessage()]);
        }

        // Resilient Fallback Heuristic PR Generation
        $detectedBudget = $intentBudget ?: $this->extractBudgetFromText($userPrompt);
        $detectedItems = $this->extractItemsFromText($userPrompt, $detectedBudget);

        $suggestedItems = [];
        if (!empty($matchedItems)) {
            $suggestedItems = array_map(fn($m) => [
                'catalogue_id'    => $m['id'] ?? null,
                'name'            => $m['name'] ?? 'Item Pengadaan',
                'item_code'       => $m['item_code'] ?? null,
                'category'        => $m['category'] ?? 'General',
                'brand'           => $m['brand'] ?? null,
                'detailed_specs'  => $m['specifications'] ?? ($m['name'] ?? ''),
                'qty'             => 1,
                'uom'             => $m['uom'] ?? 'unit',
                'estimated_price' => $detectedBudget ? round($detectedBudget / count($matchedItems)) : 15000000,
                'expected_date'   => now()->addDays(14)->toDateString(),
                'reason'          => 'Sesuai spesifikasi kebutuhan',
            ], $matchedItems);
        } else {
            $suggestedItems = array_map(fn($it) => [
                'catalogue_id'    => null,
                'name'            => $it['name'],
                'item_code'       => 'PR-' . strtoupper(substr(md5($it['name']), 0, 6)),
                'category'        => 'General Procurement',
                'brand'           => 'Standard',
                'detailed_specs'  => $it['detailed_specs'],
                'qty'             => $it['qty'],
                'uom'             => $it['uom'],
                'estimated_price' => $it['estimated_price'],
                'expected_date'   => now()->addDays(14)->toDateString(),
                'reason'          => 'Kebutuhan unit pengadaan sesuai prompt user',
            ], $detectedItems);
        }

        $totalBudgetCalculated = collect($suggestedItems)->sum(fn($i) => ($i['qty'] ?? 1) * ($i['estimated_price'] ?? 0));

        return [
            'title'                  => 'PR-' . date('Ymd') . ': Pengadaan ' . implode(', ', array_slice(array_column($suggestedItems, 'name'), 0, 2)),
            'department'             => $context['department'] ?? 'Information Technology',
            'description'            => "Pengadaan resmi untuk kebutuhan operasional perusahaan: {$userPrompt}. Seluruh unit harus memenuhi standar kualitas enterprise dengan garansi resmi dan waktu pengiriman sesuai SLA.",
            'business_justification' => 'Pengadaan ini sangat mendesak untuk menunjang kelancaran produktivitas tim operasional dan keberlangsungan proyek perusahaan.',
            'duration_days'          => 7,
            'priority'               => 'Normal',
            'suggested_items'        => $suggestedItems,
            'estimated_total_budget' => $detectedBudget ?: $totalBudgetCalculated,
            'delivery_point_recommendation' => $context['address'] ?? 'Kantor Pusat',
            'vendor_evaluation_criteria' => [
                'Kesesuaian spesifikasi teknis 100%',
                'Garansi resmi minimal 1 tahun',
                'Waktu pengiriman maksimal 14 hari kerja'
            ],
            'manager_notes'          => 'Mohon review dan approval untuk proses penawaran tender vendor.',
        ];
    }

    /**
     * Autofill metadata & spesifikasi katalog produk menggunakan OpenAI ChatGPT.
     */
    public function autofillCatalogue(string $name, ?string $categoryHint = null, ?string $companyId = null): array
    {
        $categoryHintText = $categoryHint ? "Kategori yang disarankan: {$categoryHint}" : "";
        $prompt = <<<PROMPT
Nama Produk: "{$name}"
{$categoryHintText}

Lengkapi data katalog produk B2B di atas secara akurat dan profesional.
PILIH SALAH SATU Kategori yang paling tepat dari daftar ini:
- Electronics
- Spareparts
- Construction
- Software
- Furniture
- Stationery
- Mechanical
- Chemicals
- General

PILIH SALAH SATU Satuan UOM yang paling sesuai:
- Unit
- Pc
- Set
- Box
- Pack
- Roll
- Litre
- Kg
- Meter
- License

Balas HANYA dengan JSON valid format:
{
  "category": "Salah satu kategori di atas",
  "brand": "Nama merk/brand spesifik atau 'Generic'",
  "uom": "Salah satu UOM di atas",
  "specifications": "Ringkasan spesifikasi teknis lengkap, dimensi/kapasitas, material, dan fitur utama produk (2-4 kalimat/bullet points)",
  "keywords": "kata-kunci-1, kata-kunci-2, merek, kategori, spesifikasi-kunci",
  "image_search_query": "Keyword pencarian gambar produk bahasa inggris yang sangat spesifik dan akurat di Wikipedia/Commons"
}
PROMPT;

        try {
            $res = $this->askJson($prompt, 'Kamu adalah B2B Product Master Data Specialist dan Technical Catalogue Manager yang sangat teliti.', $companyId, 'autofillCatalogue');
            if (!empty($res['category'])) {
                return $res;
            }
        } catch (\Exception $e) {
            Log::warning('OpenAiService: autofillCatalogue fallback', ['error' => $e->getMessage()]);
        }

        return [
            'category'           => $categoryHint ?: 'General',
            'brand'              => 'Generic',
            'uom'                => 'Unit',
            'specifications'     => "{$name} - Spesifikasi standar industri kualitas enterprise.",
            'keywords'           => strtolower("{$name}, general, procurement"),
            'image_search_query' => $name,
        ];
    }

    /**
     * Generate gambar produk profesional menggunakan AI Image Generation (OpenAI / AI Diffusion Engine).
     * Mengembalikan base64 data image yang siap disimpan sebagai file katalog.
     */
    public function generateProductImage(string $productName, ?string $category = null, ?string $brand = null, ?string $companyId = null): array
    {
        $brandText = $brand && strtolower($brand) !== 'generic' ? "{$brand} " : "";
        $prompt = "Clean professional studio commercial product photography of {$brandText}{$productName}, isolated on clean white background, high quality commercial B2B product photography, sharp details, realistic studio lighting, e-commerce catalog style";
        $encodedPrompt = urlencode($prompt);

        // 1. Coba OpenAI Image API jika didukung oleh project key
        if (!empty($this->apiKey)) {
            try {
                $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type'  => 'application/json',
                ])
                ->timeout(20)
                ->post('https://api.openai.com/v1/images/generations', [
                    'prompt' => substr($prompt, 0, 1000),
                    'n'      => 1,
                    'size'   => '1024x1024',
                ]);

                if ($response->successful()) {
                    $data = $response->json();
                    $b64 = $data['data'][0]['b64_json'] ?? null;
                    $url = $data['data'][0]['url'] ?? null;

                    if (!$b64 && $url) {
                        $imgFetch = Http::timeout(25)->get($url);
                        if ($imgFetch->successful()) {
                            $b64 = base64_encode($imgFetch->body());
                        }
                    }

                    if ($b64) {
                        $this->trackUsage(['prompt_tokens' => 1000, 'completion_tokens' => 0, 'total_tokens' => 1000], 'generateProductImage', $companyId);
                        return ['success' => true, 'b64_json' => $b64, 'url' => $url];
                    }
                }
            } catch (\Exception $e) {
                Log::warning('OpenAI Image API generation skipped/unavailable, falling back to AI Diffusion engine', ['error' => $e->getMessage()]);
            }
        }

        // 2. High Quality Fast AI Diffusion Image Generator (Pollinations AI Studio)
        try {
            $diffusionUrl = "https://image.pollinations.ai/prompt/{$encodedPrompt}?width=800&height=800&nologo=true&enhance=true&model=flux";
            $res = Http::timeout(30)->get($diffusionUrl);

            if ($res->successful() && strlen($res->body()) > 2000) {
                $b64 = base64_encode($res->body());
                $this->trackUsage(['prompt_tokens' => 500, 'completion_tokens' => 0, 'total_tokens' => 500], 'generateProductImage', $companyId);

                return [
                    'success'  => true,
                    'b64_json' => $b64,
                    'url'      => $diffusionUrl,
                ];
            }
        } catch (\Exception $e) {
            Log::warning('Pollinations Flux generation failed, trying Turbo model', ['error' => $e->getMessage()]);
        }

        // 3. Fallback Fast Turbo Diffusion Model
        try {
            $turboUrl = "https://image.pollinations.ai/prompt/{$encodedPrompt}?width=600&height=600&nologo=true&model=turbo";
            $res = Http::timeout(20)->get($turboUrl);

            if ($res->successful() && strlen($res->body()) > 2000) {
                $b64 = base64_encode($res->body());
                return [
                    'success'  => true,
                    'b64_json' => $b64,
                    'url'      => $turboUrl,
                ];
            }
        } catch (\Exception $e) {
            Log::error('All generative image engines failed', ['error' => $e->getMessage()]);
            throw new \RuntimeException('Gagal meng-generate gambar produk AI: ' . $e->getMessage());
        }

        throw new \RuntimeException('Gagal mendapatkan gambar produk dari AI.');
    }



    /**
     * Teks perbandingan markdown dari prompt bebas.
     */
    public function generateComparisonText(string $userQuery): string
    {
        $prompt = <<<PROMPT
User meminta perbandingan produk berikut:
"{$userQuery}"

Berikan analisis perbandingan spesifikasi teknis dan saran pengadaan yang komprehensif menggunakan pengetahuan Anda.
Tulis dalam format Markdown table yang rapi dengan kolom: Fitur | Produk A | Produk B
Sertakan baris untuk: Prosesor / Tipe, RAM / Kapasitas, Storage / Material, Display / Dimensi, Daya / Baterai, Estimasi Harga (IDR), Kelebihan, Kekurangan.
Tambahkan narasi singkat rekomendasi procurement di bawah tabel.
PROMPT;

        try {
            return $this->ask($prompt, 'Kamu adalah asisten pengadaan barang yang objektif dan ahli dalam spesifikasi teknis produk.', null, 'generateComparisonText');
        } catch (\Exception $e) {
            Log::error('OpenAiService: generateComparisonText failed', ['error' => $e->getMessage()]);
            return 'Gagal memuat perbandingan produk.';
        }
    }

    /**
     * Track penggunaan OpenAI ke database.
     */
    private function trackUsage(array $usage, string $endpoint, ?string $companyId = null): void
    {
        try {
            $promptTokens     = (int) ($usage['prompt_tokens']     ?? 0);
            $completionTokens = (int) ($usage['completion_tokens'] ?? 0);
            $totalTokens      = (int) ($usage['total_tokens']      ?? ($promptTokens + $completionTokens));

            AiUsageLog::create([
                'company_id'       => $companyId,
                'user_id'          => null, // bisa diisi dari request context jika diperlukan
                'endpoint'         => $endpoint,
                'model'            => $this->model,
                'prompt_tokens'    => $promptTokens,
                'completion_tokens'=> $completionTokens,
                'total_tokens'     => $totalTokens,
                'estimated_cost_usd' => AiUsageLog::estimateCost($this->model, $promptTokens, $completionTokens),
            ]);
        } catch (\Throwable $e) {
            // Jangan sampai tracking error memblok fitur AI
            Log::warning('OpenAiService: trackUsage failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Ambil ringkasan penggunaan AI bulan ini untuk satu company.
     */
    public function getUsageSummary(string $companyId): array
    {
        return AiUsageLog::getMonthlySummary($companyId);
    }
}
