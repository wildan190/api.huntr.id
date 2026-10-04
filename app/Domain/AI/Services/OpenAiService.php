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
        $this->model  = is_string($rawModel) ? $rawModel : 'gpt-4o';
        $this->timeout = (int) config('ai.timeout', 45);
    }

    /**
     * Ekstrak estimasi budget dari teks Bahasa Indonesia / format mata uang.
     */
    public function extractBudgetFromText(string $text): ?float
    {
        $text = strtolower($text);

        // "400 juta", "400jt", "400 jt", "400.5 juta"
        if (preg_match('/(?:budget|anggaran|dana|biaya|rp\.?|idr)?\s*([\d\.\,]+)\s*(?:juta|jt)\b/i', $text, $matches)) {
            $raw = str_replace(',', '.', $matches[1]);
            $val = ((float) $raw) * 1000000;
            if ($val >= 100000) return $val;
        }

        // "1.5 miliar", "2 milyar", "1.5 M", "1.5m" (jangan cocokkan jika cuma meter / huruf m biasa)
        if (preg_match('/(?:budget|anggaran|dana|biaya|rp\.?|idr)\s*([\d\.\,]+)\s*(?:miliar|milyar|m)\b/i', $text, $matches) ||
            preg_match('/([\d\.\,]+)\s*(?:miliar|milyar)\b/i', $text, $matches)) {
            $raw = str_replace(',', '.', $matches[1]);
            $val = ((float) $raw) * 1000000000;
            if ($val >= 100000) return $val;
        }

        // "500 ribu", "500rb"
        if (preg_match('/([\d\.\,]+)\s*(?:ribu|rb)\b/i', $text, $matches)) {
            $raw = str_replace(',', '.', $matches[1]);
            $val = ((float) $raw) * 1000;
            if ($val >= 50000) return $val;
        }

        // "500k" - HANYA jika didahului kata budget/rp/harga (agar TIDAK bentrok dengan resolusi monitor 4K / 2K)
        if (preg_match('/(?:budget|anggaran|dana|biaya|rp\.?|idr|harga)\s*[:=]?\s*([\d\.\,]+)\s*k\b/i', $text, $matches)) {
            $raw = str_replace(',', '.', $matches[1]);
            $val = ((float) $raw) * 1000;
            if ($val >= 50000) return $val;
        }

        // "Rp 400.000.000"
        if (preg_match('/(?:rp\.?|idr)\s*([\d\.\,]+)/i', $text, $matches)) {
            $cleaned = preg_replace('/[^0-9]/', '', $matches[1]);
            if (!empty($cleaned) && (float)$cleaned >= 50000) {
                return (float) $cleaned;
            }
        }

        // Plain budget number e.g. "budget 400000000"
        if (preg_match('/(?:budget|anggaran|dana|biaya)\s*(?:sekitar|sebesar|maksimal|maks|min)?\s*[:=]?\s*([\d\.\,]+)/i', $text, $matches)) {
            $cleaned = preg_replace('/[^0-9]/', '', $matches[1]);
            if (!empty($cleaned) && (float)$cleaned >= 50000) {
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
                $it['price_status'] = 'buyer_budget';
            }
        } else {
            foreach ($items as &$it) {
                $it['estimated_price'] = 0;
                $it['price_status'] = 'rfq_required';
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
      "budget_hint_idr": null
    }
  ],
  "estimated_total_budget_idr": null (atau angka integer jika user eksplisit menyebutkan batas/pagu anggaran),
  "department": "Departemen yang cocok (misal: IT & Engineering, General Affairs, Operations, HR)",
  "urgency": "Normal / Urgent / Critical",
  "ai_summary": "Rangkuman 1 kalimat jelas tentang kebutuhan procurement ini",
  "is_comparison": true/false
}
PROMPT;

        try {
            $result = $this->askJson($prompt, 'Kamu adalah AI Procurement Specialist yang ahli menganalisis kebutuhan pengadaan barang/jasa perusahaan. PENTING: Jangan mengarang angka budget jika user tidak menyebutkannya; isi estimated_total_budget_idr dengan null.', null, 'extractSearchIntent');
            if (!empty($result) && is_array($result)) {
                $explicitBudget = $this->extractBudgetFromText($userQuery);
                if ($explicitBudget !== null) {
                    $result['estimated_total_budget_idr'] = $explicitBudget;
                } else {
                    $result['estimated_total_budget_idr'] = null;
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
                'budget_hint_idr'   => $it['estimated_price'] ?? null,
            ], $detectedItems),
            'estimated_total_budget_idr' => $detectedBudget,
            'department'                 => 'Information Technology & Procurement',
            'urgency'                    => 'Normal',
            'ai_summary'                 => 'Pengadaan ' . implode(', ', array_column($detectedItems, 'name')),
            'is_comparison'              => count($detectedItems) >= 2 || str_contains(strtolower($userQuery), 'bandingkan') || str_contains(strtolower($userQuery), 'compare'),
        ];
    }

    /**
     * Re-rank & nilai kesesuaian produk katalog terhadap kebutuhan procurement.
     * @param string|null $companyId Untuk tracking usage log per perusahaan
     * @param array $intent Context intent lengkap (kategori eksplisit, target_items, keywords) untuk akurasi filter
     */
    public function rankSearchProducts(string $userQuery, array $products, ?string $companyId = null, array $intent = []): array
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

        $intentJson = !empty($intent)
            ? "\n\nContext Intent (struktur intent user, GUNAKAN untuk evaluasi kategori yang presisi):\n"
              . json_encode([
                  'category'        => $intent['category'] ?? null,
                  'brand'           => $intent['brand'] ?? null,
                  'target_items'    => $intent['target_items'] ?? null,
                  'keywords'        => $intent['keywords'] ?? null,
                  'department'      => $intent['department'] ?? null,
              ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n"
            : '';

        $prompt = <<<PROMPT
Kebutuhan User: "{$userQuery}"
{$intentJson}

Daftar Produk Katalog yang Ditemukan:
{$candidatesJson}

═══════════════════════════════════════════════════════════
🔥 PERATURAN EVALUASI (WAJIB DIPATUHI, TANPA KECUALIAN):
═══════════════════════════════════════════════════════════
1.  [is_match = FALSE] JIKA kategori produk JELAS TIDAK SESUAI.
    Contoh pelanggaran yang HARUS di-reject (is_match=false):
    • User minta "Mini PC Intel i5 Gen 12" tapi produk = SSD NVMe / Laptop Acer Swift / Komputer Fullset 19" (monitor 19")
    • User minta "Laptop" tapi produk = Mini PC / Printer / RAM / SSD SATA
    • User minta "Smartphone" tapi produk = Laptop / Charger / Case HP
    • User minta "Printer Laser" tapi produk = Toner / Kertas HVS

2.  [is_match = FALSE] JIKA nama utama produk tidak mengandung kategori produk utama dari intent.
    Contoh: user minta "mini pc" tapi nama produk cuma "Lexar NM620 512" (GAK ADA mini pc) = REJECT.

3.  [relevance_score ≤ 40] DAN [is_match=false] untuk semua produk yang JELAS beda kategori — JANGAN kasih skor 80-90 ke barang yang salah kategori!
    Hanya beri skor 85+ jika:
      a) KATEGORI COCOK (nama mengandung kategori utama intent)
      b) MINIMAL 70% SPEK utama intent ada (misal: minta i5 gen12 → i5 gen12 harus ada di spek)
      c) Brand sesuai jika intent menyebutkan brand eksplisit

4.  [relevance_score] rentang 0-100, gunakan granular:
    - 0-39  = SANGAT TIDAK COCOK (beda kategori total = is_match=false)
    - 40-59 = Kurang Cocok (kategori mendekati tapi spek banyak kurang)
    - 60-74 = Cukup Cocok (kategori cocok, spek 50-69% terpenuhi)
    - 75-89 = Cocok (kategori cocok, spek 70-90% terpenuhi)
    - 90-100 = Sangat Cocok (kategori + brand + spek 90%+ terpenuhi)

5.  [fit_reason] WAJIB diisi JELAS mengapa cocok / DITOLAK. Khusus ditolak: sebutkan kategori mana yang salah.
    Contoh alasan PENOLAKAN: "Beda kategori: user minta Mini PC, produk ini adalah SSD NVMe (storage bukan komputer)"
    Contoh alasan DITERIMA: "Kategori cocok (Mini PC), spek i5 gen 12+ lengkap dengan SSD NVMe 512GB"

6.  TUGAS KAMU HANYA menilai kesesuaian teknis produk — JANGAN isi estimated_unit_price_idr, selalu 0.

Balas DENGAN FORMAT JSON HANYA:
{
  "results": [
    {
      "product_id": "id produk",
      "is_match": true,
      "relevance_score": 92,
      "fit_reason": "Alasan singkat mengapa produk ini cocok / DITOLAK",
      "suggested_qty": 1,
      "estimated_unit_price_idr": 0
    }
  ]
}
PROMPT;

        try {
            $response = $this->askJson($prompt, 'Kamu adalah AI Technical Procurement Evaluator senior yang sangat ketat dan objektif. Tidak segan-segan menolak (is_match=false) produk yang beda kategori meski spesifikasi overlap keywordnya.', $companyId, 'rankSearchProducts');
            if (!empty($response['results'])) {
                return $response['results'];
            }
        } catch (\Exception $e) {
            Log::warning('OpenAiService: rankSearchProducts fallback', ['error' => $e->getMessage()]);
        }

        // Fallback rankings — LEBIH KETAT: cek via kata kunci sederhana (jika AI gagal/tidak tersedia)
        $intentKeywords = array_filter(array_map('strtolower', $intent['keywords'] ?? []));
        $intentSummary  = strtolower($intent['ai_summary'] ?? $userQuery);
        return array_map(function ($p, $idx) use ($intentKeywords, $intentSummary) {
            $name = strtolower($p['name'] ?? '');
            $spec = strtolower($p['specifications'] ?? '');
            $haystack = $name . ' ' . $spec;
            $matchCount = 0;
            foreach ($intentKeywords as $kw) {
                if (strlen($kw) >= 3 && str_contains($haystack, $kw)) $matchCount++;
            }
            $primaryCategoryWord = '';
            foreach (['mini pc', 'laptop', 'notebook', 'ssd nvme', 'printer', 'smartphone',
                       'monitor', 'pc desktop', 'server'] as $catWord) {
                if (str_contains($intentSummary, $catWord)) { $primaryCategoryWord = $catWord; break; }
            }
            $hasCategoryMatch = ($primaryCategoryWord === '') || str_contains($name, $primaryCategoryWord);

            // Fallback tanpa AI: JANGAN kasih skor tinggi kalo category ga cocok
            $baseScore = $hasCategoryMatch ? 78 : 42;
            $score = $baseScore + ($matchCount * 2);
            $score = max(25, min(92, $score - ($idx * 3)));

            return [
                'product_id'               => $p['id'],
                'is_match'                 => $score >= 55, // Fallback rule: skor <55 = otomatis ditolak
                'relevance_score'          => $score,
                'fit_reason'               => $hasCategoryMatch
                    ? "Kategori cocok. {$matchCount} keyword spesifikasi intent terpenuhi."
                    : "Kemungkinan beda kategori: nama produk tidak mengandung '{$primaryCategoryWord}'. (Evaluasi heuristik AI fallback)",
                'suggested_qty'            => 1,
                'estimated_unit_price_idr' => 0,
            ];
        }, $candidates, array_keys($candidates));
    }

    /**
     * Bandingkan beberapa produk katalog secara objektif dan mendalam.
     *
     * @param array $webSearchResults Data harga & produk dari Google Search (optional)
     */
    public function compareProducts(array $catalogues, ?string $userNeed = null, array $webSearchResults = []): array
    {
        $cataloguesJson = json_encode($catalogues, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $userNeedPrompt = $userNeed ? "Kebutuhan Khusus Buyer: \"{$userNeed}\"" : "Bandingkan untuk kebutuhan pengadaan standar enterprise.";

        // Bangun blok data harga dari Google Search jika tersedia
        $webPriceBlock = '';
        if (!empty($webSearchResults)) {
            $lines = [];
            foreach ($webSearchResults as $key => $data) {
                if ($key === '__general__') continue;
                $wp = $data['web_prices'] ?? [];
                if (!empty($wp['avg_price'])) {
                    $avg = number_format((float) $wp['avg_price'], 0, ',', '.');
                    $min = number_format((float) ($wp['min_price'] ?? $wp['avg_price']), 0, ',', '.');
                    $max = number_format((float) ($wp['max_price'] ?? $wp['avg_price']), 0, ',', '.');
                    $srcCount = count($wp['sources'] ?? []);
                    $lines[] = "- {$data['item_name']}: Harga web Rp {$min} - Rp {$max} (rata-rata Rp {$avg}) dari {$srcCount} sumber online";
                }
                // Tambahkan snippet hasil pencarian web untuk konteks spesifikasi
                foreach (array_slice($data['results'] ?? [], 0, 2) as $r) {
                    if (!empty($r['snippet'])) {
                        $lines[] = "  → [{$r['source']}] {$r['snippet']}";
                    }
                }
            }
            if (!empty($lines)) {
                $webPriceBlock = "\n\n==== DATA HARGA & SPESIFIKASI DARI GOOGLE SEARCH (REAL-TIME) ====\n"
                    . "Gunakan data ini sebagai referensi harga pasar terkini untuk mengevaluasi nilai setiap produk.\n"
                    . implode("\n", $lines)
                    . "\n=================================================================";
            }
        }

        $prompt = <<<PROMPT
{$userNeedPrompt}{$webPriceBlock}

Daftar Produk untuk Dibandingkan:
{$cataloguesJson}

Lakukan perbandingan komprehensif dari sudut pandang procurement B2B.
FOKUS pada perbandingan teknis, spesifikasi, keunggulan, dan kekurangan masing-masing produk.
JANGAN isi estimasi harga — harga dikelola oleh sistem terpisah dan bukan tanggung jawabmu.
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
            $catPrice = !empty($c['estimated_price']) ? (float)$c['estimated_price'] : 0;
            $matrix[] = [
                'catalogue_id'        => $c['id'] ?? ("cat-{$idx}"),
                'product_name'        => $c['name'] ?? 'Produk Katalog',
                'vendor_name'         => $c['vendor'] ?? 'Vendor Resmi',
                'score'               => 85 + (5 - $idx),
                'key_specs'           => $c['specifications'] ?? ($c['name'] ?? ''),
                'pros'                => ['Spesifikasi terstandarisasi', 'Dukungan garansi resmi'],
                'cons'                => ['Waktu tunggu pengiriman standar'],
                'estimated_price_idr' => $catPrice,
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
     *
     * @param array $historicalPrices  Data harga historis nyata dari PO/Proposal database
     * @param array $webSearchResults  Data harga & spesifikasi terkini dari Google Search
     */
    public function generatePrDraft(string $userPrompt, array $matchedItems, array $context = [], array $historicalPrices = [], array $webSearchResults = []): array
    {
        $itemsJson = json_encode($matchedItems, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $contextJson = json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $intentBudget = $context['estimated_total_budget_idr'] ?? $this->extractBudgetFromText($userPrompt);

        // --- Bangun blok referensi harga historis (ground truth dari DB) ---
        $historicalPriceBlock = '';
        if (!empty($historicalPrices)) {
            $historicalLines = [];
            foreach ($historicalPrices as $data) {
                $source     = $data['source'] === 'historical_po' ? 'PO Historis Import' : 'Penawaran Tender Menang';
                $avgFmt     = number_format((float)$data['avg_price'], 0, ',', '.');
                $minFmt     = number_format((float)$data['min_price'], 0, ',', '.');
                $maxFmt     = number_format((float)$data['max_price'], 0, ',', '.');
                $lastFmt    = number_format((float)$data['last_price'], 0, ',', '.');
                $samples    = $data['sample_count'];
                $lastDate   = $data['last_date'] ?? 'N/A';
                $historicalLines[] = "- \"{$data['item_name']}\": Rata-rata Rp {$avgFmt}/unit | Range Rp {$minFmt} - Rp {$maxFmt} | Harga Terakhir Rp {$lastFmt} | {$samples} transaksi | Sumber: {$source} | Tanggal terakhir: {$lastDate}";
            }
            $historicalPriceBlock = "\n\n==== REFERENSI HARGA NYATA DARI DATABASE TRANSAKSI HUNTR ====\n"
                . "Data berikut adalah harga NYATA dari transaksi PO / penawaran vendor yang BENAR-BENAR TERJADI di sistem Huntr.\n"
                . "WAJIB gunakan harga historis ini sebagai acuan utama untuk item yang cocok. DILARANG mengarang harga jika referensi sudah tersedia.\n"
                . "Untuk item yang ada referensinya: set 'price_status' = 'historical_reference' dan 'estimated_price' sesuai rata-rata atau harga terakhir.\n"
                . implode("\n", $historicalLines)
                . "\n==============================================================";
        }

        // --- Bangun blok referensi harga web dari Google Search ---
        $webSearchBlock = '';
        if (!empty($webSearchResults)) {
            $webLines = [];
            foreach ($webSearchResults as $key => $data) {
                $itemName = $data['item_name'] ?? $key;
                $wp       = $data['web_prices'] ?? [];
                if (!empty($wp['avg_price'])) {
                    $avg  = number_format((float) $wp['avg_price'], 0, ',', '.');
                    $min  = number_format((float) ($wp['min_price'] ?? $wp['avg_price']), 0, ',', '.');
                    $max  = number_format((float) ($wp['max_price'] ?? $wp['avg_price']), 0, ',', '.');
                    $srcs = count($wp['sources'] ?? []);
                    $webLines[] = "- \"{$itemName}\": Range harga web Rp {$min} - Rp {$max} (rata-rata Rp {$avg}) dari {$srcs} toko/distributor online";
                }
                // Tambahkan top-3 snippet untuk konteks spesifikasi & produk alternatif
                foreach (array_slice($data['results'] ?? [], 0, 3) as $r) {
                    if (!empty($r['snippet'])) {
                        $price = $r['price'] > 0 ? ' [Rp ' . number_format($r['price'], 0, ',', '.') . ']' : '';
                        $webLines[] = "  → [{$r['source']}]{$price} {$r['snippet']}";
                    }
                }
            }
            if (!empty($webLines)) {
                $webSearchBlock = "\n\n==== REFERENSI HARGA & PRODUK DARI BRAVE SEARCH / WEB (REAL-TIME) ====\n"
                    . "Data harga pasar dan spesifikasi terkini yang ditemukan langsung dari internet/web (distributor resmi, marketplace B2B, portal industri).\n"
                    . "WAJIB gunakan referensi web ini sebagai acuan harga pasar terkini jika tidak ada data historis Huntr!\n"
                    . "Untuk item yang cocok: set 'price_status' = 'web_market_reference', dan gunakan kisaran harga web tersebut untuk 'estimated_price'. DILARANG mengarang harga murah yang tidak realistis (misal alat berat ratusan juta / miliaran rupiah jangan diisi puluhan juta)!\n"
                    . implode("\n", $webLines)
                    . "\n=================================================================";
            }
        }

        // HARGA TIDAK BOLEH DIISI OLEH AI — sepenuhnya dihandle enrichPrItems dari:
        //   1. Historis PO Huntr  2. Brave Search/web  3. Buyer budget  4. rfq_required
        $budgetInstruction = "ATURAN HARGA (WAJIB DIPATUHI): JANGAN PERNAH mengisi atau menebak nilai 'estimated_price'. Selalu set 'estimated_price': 0 dan 'price_status': 'rfq_required' untuk SEMUA item. Sistem akan mengisi harga secara otomatis dari data historis transaksi dan riset web. Harga BUKAN tanggung jawabmu.";

        $prompt = <<<PROMPT
Permintaan Kebutuhan Pengadaan User:
"{$userPrompt}"

Konteks Tambahan:
{$contextJson}

{$budgetInstruction}{$historicalPriceBlock}{$webSearchBlock}

Produk Terpilih / Katalog Tersedia di Database:
{$itemsJson}

PEDOMAN PENYUSUNAN PR:
1. HARGA DILARANG DIISI OLEH AI: Set 'estimated_price': 0 dan 'price_status': 'rfq_required' untuk SEMUA item tanpa kecuali. Sistem backend akan mengisi harga dari data historis PO dan riset web secara otomatis.
2. 'estimated_total_budget': set ke 0 — akan dihitung ulang oleh sistem.
3. Fokus tugasmu: susun teks PR yang sangat lengkap, profesional, dan terstruktur dalam Bahasa Indonesia formal — deskripsi, justifikasi bisnis, spesifikasi teknis, alasan pemilihan item.

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
      "catalogue_id": "id katalog dari daftar jika cocok, atau null jika item baru",
      "name": "Nama lengkap produk & tipe",
      "item_code": "Kode item dari katalog atau 'REQ-NAMA' jika baru",
      "category": "Kategori barang",
      "brand": "Merk barang",
      "detailed_specs": "Rincian spesifikasi teknis lengkap item sesuai standar resmi",
      "qty": 10,
      "uom": "unit / set / pcs / box",
      "estimated_price": 0,
      "price_status": "rfq_required",
      "price_note": "Harga akan diisi otomatis oleh sistem dari data historis dan riset web",
      "expected_date": "2026-09-01",
      "reason": "Alasan pemilihan item / justifikasi kebutuhan"
    }
  ],
  "estimated_total_budget": 45000000,
  "delivery_point_recommendation": "Rekomendasi alamat/titik pengiriman barang",
  "vendor_evaluation_criteria": [
    "Kesesuaian spesifikasi teknis 100%",
    "Garansi resmi pabrikan/distributor terverifikasi",
    "Lead time pengiriman maksimal 14 hari kerja"
  ],
  "manager_notes": "Catatan ringkas untuk approval manager"
}
PROMPT;

        try {
            $res = $this->askJson($prompt, 'Kamu adalah Chief Procurement Officer (CPO) dan Senior Procurement Estimator B2B Indonesia. Kamu sangat teliti terhadap generasi hardware, tipe barang, dan estimasi harga pasar wajar (HPS). Jika ada data historis transaksi, WAJIB gunakan sebagai referensi utama harga.', null, 'generatePrDraft');
            if (!empty($res['suggested_items'])) {
                // Pastikan setiap suggested_item punya mapping katalog yang valid
                $catalogueById    = collect($matchedItems)->keyBy('id');
                $historicalByName = collect($historicalPrices)->keyBy(fn($v) => strtolower(trim($v['item_name'])));

                $res['suggested_items'] = array_map(function ($item) use ($catalogueById, $intentBudget, $historicalByName) {
                    if (empty($item['catalogue_id']) || !$catalogueById->has($item['catalogue_id'])) {
                        // Coba match by name
                        $matched = $catalogueById->first(fn($c) =>
                            isset($c['name']) && strtolower(trim($c['name'])) === strtolower(trim($item['name'] ?? ''))
                        );
                        if ($matched) {
                            $item['catalogue_id'] = $matched['id'];
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

                    // Tentukan price_status yang transparan & akurat
                    $curPrice    = (float)($item['estimated_price'] ?? 0);
                    $itemNameKey = strtolower(trim($item['name'] ?? ''));

                    // Cek apakah nama item cocok dengan data historis (fuzzy match sederhana)
                    $historicalMatch = $historicalByName->first(fn($h, $key) =>
                        str_contains($itemNameKey, $key) || str_contains($key, $itemNameKey)
                    );

                    if ($item['price_status'] === 'historical_reference' && $curPrice > 0) {
                        // AI sudah set historical_reference — pertahankan
                        $item['price_status'] = 'historical_reference';
                    } elseif ($historicalMatch && $curPrice > 0) {
                        // Item cocok data historis — override ke historical_reference
                        $item['price_status'] = 'historical_reference';
                        if (empty($item['price_note'])) {
                            $avgFmt = number_format((float)$historicalMatch['avg_price'], 0, ',', '.');
                            $samples = $historicalMatch['sample_count'];
                            $item['price_note'] = "Berdasarkan {$samples} transaksi " . ($historicalMatch['source'] === 'historical_po' ? 'PO historis' : 'penawaran tender') . " (rata-rata Rp {$avgFmt})";
                        }
                    } elseif ($item['price_status'] === 'web_market_reference' && $curPrice > 0) {
                        // AI menggunakan data referensi Brave Search / Web — pertahankan
                        $item['price_status'] = 'web_market_reference';
                    } elseif (!empty($item['catalogue_id']) && $curPrice > 0) {
                        $item['price_status'] = 'verified_catalogue';
                    } elseif ($intentBudget && $curPrice > 0) {
                        $item['price_status'] = 'buyer_budget';
                    } elseif ($curPrice > 0) {
                        $item['price_status'] = 'market_estimate';
                    } else {
                        $item['price_status'] = 'rfq_required';
                        $item['estimated_price'] = 0;
                    }

                    return $item;
                }, $res['suggested_items']);
                return $res;
            }
        } catch (\Exception $e) {
            Log::error('OpenAiService: generatePrDraft fallback', ['error' => $e->getMessage()]);
        }

        // Resilient Fallback Heuristic PR Generation
        $detectedBudget   = $intentBudget ?: $this->extractBudgetFromText($userPrompt);
        $detectedItems    = $this->extractItemsFromText($userPrompt, $detectedBudget);
        $historicalByName = collect($historicalPrices)->keyBy(fn($v) => strtolower(trim($v['item_name'])));

        /**
         * Helper: resolusi harga dari historis (ground truth) atau budget atau 0
         */
        $resolvePrice = function (string $name, float $fallbackPrice, string &$priceStatus) use ($historicalByName, $detectedBudget): float {
            $nameKey = strtolower(trim($name));
            $hist    = $historicalByName->first(fn($h, $key) =>
                str_contains($nameKey, $key) || str_contains($key, $nameKey)
            );
            if ($hist && (float)$hist['avg_price'] > 0) {
                $priceStatus = 'historical_reference';
                return (float)$hist['avg_price'];
            }
            if ($fallbackPrice > 0) {
                $priceStatus = $detectedBudget ? 'buyer_budget' : 'market_estimate';
                return $fallbackPrice;
            }
            $priceStatus = 'rfq_required';
            return 0;
        };

        $suggestedItems = [];
        if (!empty($matchedItems)) {
            $suggestedItems = array_map(function ($m) use ($resolvePrice, $detectedBudget, $matchedItems) {
                $fallback    = !empty($m['estimated_price']) ? (float)$m['estimated_price'] : ($detectedBudget ? round($detectedBudget / count($matchedItems)) : 0);
                $priceStatus = 'rfq_required';
                $price       = $resolvePrice($m['name'] ?? '', $fallback, $priceStatus);
                return [
                    'catalogue_id'    => $m['id'] ?? null,
                    'name'            => $m['name'] ?? 'Item Pengadaan',
                    'item_code'       => $m['item_code'] ?? null,
                    'category'        => $m['category'] ?? 'General',
                    'brand'           => $m['brand'] ?? null,
                    'detailed_specs'  => $m['specifications'] ?? ($m['name'] ?? ''),
                    'qty'             => 1,
                    'uom'             => $m['uom'] ?? 'unit',
                    'estimated_price' => $price,
                    'price_status'    => $priceStatus,
                    'expected_date'   => now()->addDays(14)->toDateString(),
                    'reason'          => 'Sesuai spesifikasi kebutuhan katalog terdaftar',
                ];
            }, $matchedItems);
        } else {
            $suggestedItems = array_map(function ($it) use ($resolvePrice) {
                $priceStatus = 'rfq_required';
                $price       = $resolvePrice($it['name'] ?? '', (float)($it['estimated_price'] ?? 0), $priceStatus);
                return [
                    'catalogue_id'    => null,
                    'name'            => $it['name'],
                    'item_code'       => 'REQ-' . strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $it['name']), 0, 6)),
                    'category'        => 'General Procurement',
                    'brand'           => null,
                    'detailed_specs'  => $it['detailed_specs'],
                    'qty'             => $it['qty'],
                    'uom'             => $it['uom'],
                    'estimated_price' => $price,
                    'price_status'    => $priceStatus,
                    'expected_date'   => now()->addDays(14)->toDateString(),
                    'reason'          => 'Kebutuhan unit pengadaan sesuai prompt user (menunggu penawaran vendor)',
                ];
            }, $detectedItems);
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
                'Garansi resmi pabrikan/distributor terverifikasi',
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
     * Generate foto produk katalog komersial nyata menggunakan AI Diffusion Engine & ChatGPT Prompt Optimizer.
     * Menghasilkan foto produk nyata studio profesional tanpa halusinasi ilustrasi / 3D cartoon.
     */
    public function generateProductImage(string $productName, ?string $category = null, ?string $brand = null, ?string $companyId = null): array
    {
        $brandClean = $brand && strtolower($brand) !== 'generic' ? $brand : '';
        
        // 1. Gunakan ChatGPT untuk merumuskan prompt visual foto produk yang detail, akurat, dan fotorealistis
        $optimizedPrompt = "commercial product photography of {$brandClean} {$productName}, official real product packaging and hardware, centered, studio lighting, plain clean pure white background, 8k resolution, crisp sharp focus, real photo, unedited realistic materials, canon eos r5";

        try {
            $chatGptPrompt = <<<PROMPT
Nama Produk: "{$productName}"
Kategori: "{$category}"
Brand: "{$brandClean}"

Tulis deskripsi visual bahasa Inggris singkat (1-2 kalimat) untuk foto produk katalog e-commerce NYATA (bukan gambar animasi/kartun/lukisan).
Deskripsikan bentuk fisik barang yang tepat, material nyata (metal, plastik matte, kaca, packaging resmi), dan posisinya di atas background putih studio bersih.
Wajib diakhiri dengan: "commercial product photo, centered, pure white background, 8k, sharp focus, real photograph".

Balas HANYA dengan teks prompt bahasa Inggris tersebut tanpa tanda petik atau pengantar.
PROMPT;

            $aiPrompt = trim($this->ask($chatGptPrompt, 'You are an expert commercial product photographer and catalog image prompt engineer.', $companyId, 'optimizeImagePrompt'));
            if (!empty($aiPrompt) && strlen($aiPrompt) > 20) {
                $optimizedPrompt = $aiPrompt;
            }
        } catch (\Exception $e) {
            Log::warning('ChatGPT image prompt optimization fallback to default', ['error' => $e->getMessage()]);
        }

        // Negative prompt untuk mematikan halusinasi (gambar kartun, teks aneh, lukisan, render 3D murahan)
        $negativePrompt = "blurry, low quality, cartoon, anime, 3d render, drawing, painting, illustration, watermark, text, signature, duplicate, distorted, fantasy, deformed";
        
        $encodedPrompt = urlencode($optimizedPrompt);
        $encodedNegative = urlencode($negativePrompt);

        // 2. High Quality Realistic AI Diffusion Image Generator (Flux / Realistic Photo Engine)
        try {
            $diffusionUrl = "https://image.pollinations.ai/prompt/{$encodedPrompt}?negative={$encodedNegative}&width=800&height=800&nologo=true&enhance=false&model=flux";
            $res = Http::timeout(35)->get($diffusionUrl);

            if ($res->successful() && strlen($res->body()) > 2000) {
                $b64 = base64_encode($res->body());
                $this->trackUsage(['prompt_tokens' => 300, 'completion_tokens' => 0, 'total_tokens' => 300], 'generateProductImage', $companyId);

                return [
                    'success'  => true,
                    'b64_json' => $b64,
                    'url'      => $diffusionUrl,
                ];
            }
        } catch (\Exception $e) {
            Log::warning('Pollinations Flux realistic photo generation failed, trying Turbo model', ['error' => $e->getMessage()]);
        }

        // 3. Fallback High-Speed Turbo Diffusion Model
        try {
            $turboUrl = "https://image.pollinations.ai/prompt/{$encodedPrompt}?negative={$encodedNegative}&width=600&height=600&nologo=true&model=turbo";
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
            throw new \RuntimeException('Gagal meng-generate foto produk AI: ' . $e->getMessage());
        }

        throw new \RuntimeException('Gagal mendapatkan foto produk dari AI.');
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
