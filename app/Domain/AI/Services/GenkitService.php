<?php

namespace App\Domain\AI\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * GenkitService
 *
 * Wrapper untuk Google Gemini REST API (digunakan oleh Genkit framework).
 * Bertanggung jawab untuk semua komunikasi dengan AI model.
 */
class GenkitService
{
    private string $apiKey;
    private string $endpoint;
    private string $model;
    private int $timeout;
    private int $maxTokens;

    public function __construct()
    {
        $this->apiKey   = config('ai.genkit_api_key', '');
        $this->endpoint = config('ai.endpoint', 'https://generativelanguage.googleapis.com/v1beta');
        $this->model    = config('ai.model', 'gemini-2.0-flash');
        $this->timeout  = (int) config('ai.timeout', 30);
        $this->maxTokens = (int) config('ai.max_tokens', 2048);
    }

    /**
     * Kirim prompt ke Gemini dan dapatkan teks response.
     */
    public function ask(string $prompt, string $systemInstruction = ''): string
    {
        $url = "{$this->endpoint}/models/{$this->model}:generateContent?key={$this->apiKey}";

        $payload = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt]
                    ]
                ]
            ],
            'generationConfig' => [
                'maxOutputTokens' => $this->maxTokens,
                'temperature'     => 0.3,
            ],
        ];

        if (!empty($systemInstruction)) {
            $payload['systemInstruction'] = [
                'parts' => [['text' => $systemInstruction]]
            ];
        }

        try {
            $response = Http::retry(3, 2000, function ($exception, $request) {
                return true;
            }, throw: false)
                ->timeout($this->timeout)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post($url, $payload);

            if ($response->status() === 429) {
                Log::warning('GenkitService: Rate limit (429) hit from Gemini API', [
                    'status' => 429,
                    'body'   => $response->body(),
                ]);
                throw new \RuntimeException('Batas kuota layanan AI tercapai (429 Rate Limit). Silakan coba lagi beberapa saat lagi.');
            }

            if ($response->failed()) {
                Log::error('GenkitService: API error', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
                throw new \RuntimeException('AI service returned an error: ' . $response->status());
            }

            $data = $response->json();
            return $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

        } catch (\Exception $e) {
            Log::error('GenkitService: Request failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Kirim prompt dan minta response dalam format JSON.
     * Otomatis parse JSON dari response AI.
     */
    public function askJson(string $prompt, string $systemInstruction = ''): array
    {
        $jsonPrompt = $prompt . "\n\nPenting: Balas HANYA dengan JSON valid, tanpa markdown code block, tanpa penjelasan tambahan.";
        $rawResponse = $this->ask($jsonPrompt, $systemInstruction);

        // Bersihkan markdown code block jika ada
        $cleaned = preg_replace('/^```(?:json)?\s*/m', '', $rawResponse);
        $cleaned = preg_replace('/\s*```$/m', '', $cleaned);
        $cleaned = trim($cleaned);

        $decoded = json_decode($cleaned, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::warning('GenkitService: Failed to parse JSON response', [
                'raw'   => $rawResponse,
                'error' => json_last_error_msg(),
            ]);
            // Coba ekstrak JSON dari dalam teks
            preg_match('/\{.*\}/s', $cleaned, $matches);
            if (!empty($matches[0])) {
                $decoded = json_decode($matches[0], true);
            }

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException('AI returned invalid JSON: ' . json_last_error_msg());
            }
        }

        return $decoded ?? [];
    }

    /**
     * Extract search intent dari natural language query user.
     * Return: keywords, category, brand, specs hints.
     */
    public function extractSearchIntent(string $userQuery): array
    {
        $prompt = <<<PROMPT
Analisis permintaan pengadaan/procurement berikut dan ekstrak informasi pencarian produk.

Permintaan user: "{$userQuery}"

Penting:
- Jika user menyebut kata seperti "compare", "bandingkan", "vs", "versus", "perbandingan", atau menyebut dua produk/merek berbeda secara bersamaan, set "is_comparison" ke true.
- Jika user menyebut "rekomendasi", "rekomendasikan", "sarankan", "pilihkan", set "ai_recommendation" ke true.
- Untuk keywords, ekstrak semua nama produk, merek, dan spesifikasi yang relevan.

Balas dengan JSON format berikut:
{
  "keywords": ["kata kunci 1", "kata kunci 2"],
  "category": "kategori produk atau null",
  "brand": "merek yang disebutkan atau null",
  "specs": ["spesifikasi yang disebutkan"],
  "quantity_hint": "jumlah yang disebutkan atau null",
  "ai_summary": "rangkuman 1 kalimat tentang apa yang dicari user",
  "is_comparison": true/false (apakah user meminta perbandingan barang?),
  "ai_recommendation": true/false (apakah user meminta rekomendasi?)
}
PROMPT;

        try {
            return $this->askJson($prompt, 'Kamu adalah asisten pengadaan barang/jasa yang ahli menganalisis kebutuhan B2B procurement.');
        } catch (\Exception $e) {
            // Fallback ke simple keyword extraction
            return [
                'keywords'            => explode(' ', $userQuery),
                'category'            => null,
                'brand'               => null,
                'specs'               => [],
                'quantity_hint'       => null,
                'ai_summary'          => $userQuery,
                'is_comparison'       => false,
            ];
        }
    }

    /**
     * Menghasilkan teks perbandingan produk dalam format Markdown menggunakan AI knowledge.
     */
    public function generateComparisonText(string $userQuery): string
    {
        $prompt = <<<PROMPT
User meminta perbandingan produk berikut:
"{$userQuery}"

Berikan analisis perbandingan spesifikasi teknis dan saran pengadaan yang komprehensif menggunakan pengetahuan luar Anda tentang produk-produk tersebut.
Tulis dalam format Markdown table yang rapi dengan kolom: Fitur | Produk A | Produk B
Sertakan baris untuk: Prosesor, RAM, Storage, Display, Baterai, Harga Estimasi, Kelebihan, Kekurangan
Tambahkan narasi singkat rekomendasi di bawah tabel.
PROMPT;

        try {
            // Gunakan timeout lebih lama untuk comparison karena output lebih panjang
            $originalTimeout = $this->timeout;
            $this->timeout = max($this->timeout, 60);
            $result = $this->ask($prompt, 'Kamu adalah asisten pengadaan barang yang objektif dan ahli dalam spesifikasi teknis hardware dan software.');
            $this->timeout = $originalTimeout;
            return $result;
        } catch (\Exception $e) {
            Log::error('GenkitService: generateComparisonText failed', ['error' => $e->getMessage()]);
            return 'Gagal memuat analisis perbandingan eksternal.';
        }
    }

    /**
     * Re-rank dan evaluasi kecocokan produk kandidat dengan query pencarian user.
     */
    public function rankSearchProducts(string $userQuery, array $products): array
    {
        if (empty($products)) {
            return [];
        }

        $productsJson = json_encode(collect($products)->map(fn($p) => [
            'id' => $p['id'],
            'name' => $p['name'],
            'category' => $p['category'] ?? null,
            'brand' => $p['brand'] ?? null,
            'specifications' => $p['specifications'] ?? null,
        ])->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $prompt = <<<PROMPT
Kamu adalah asisten pencarian barang/jasa pintar untuk platform procurement.
User mencari produk dengan query: "{$userQuery}"

Berikut adalah daftar produk kandidat dari database:
{$productsJson}

Evaluasi setiap produk kandidat apakah cocok dengan kebutuhan user.
- Jika user meminta spesifikasi khusus (misalnya: RAM minimum 16GB, SSD 512GB, dsb) tetapi data spesifikasi dari database kosong/tidak lengkap, gunakan General AI Knowledge (pengetahuan luar Anda) tentang model produk tersebut untuk menentukan spesifikasi sebenarnya (seperti kapasitas RAM, prosesor, SSD bawaannya). Nilai kecocokan berdasarkan spesifikasi sebenarnya tersebut.
- Jika produk tidak memenuhi kriteria spesifikasi minimum user, set "is_match" menjadi false dan berikan "relevance_score" yang rendah.
- Jika user tidak menyebutkan detail spesifikasi (hanya mencari berdasarkan nama/brand/kategori), maka fokus pada kecocokan nama, kategori, dan brand produk. Set "is_match" menjadi true jika nama/brand cocok.
- Berikan skor relevansi ("relevance_score") dari 0 hingga 100.
- Berikan penjelasan singkat ("explanation") dalam Bahasa Indonesia (maksimal 1 kalimat) mengapa produk ini cocok atau kurang cocok. Jika Anda mendeteksi spesifikasi dari luar, sebutkan di penjelasan (misal: "Berdasarkan info produk, laptop ini memiliki RAM 8GB (kurang dari syarat 16GB)").

Balas dengan format JSON:
{
  "results": [
    {
      "product_id": "id dari daftar di atas",
      "is_match": true,
      "relevance_score": 95,
      "explanation": "Alasan singkat kecocokan"
    }
  ]
}
PROMPT;

        try {
            $response = $this->askJson($prompt, 'Kamu adalah ahli evaluator spesifikasi produk yang sangat teliti.');
            return $response['results'] ?? [];
        } catch (\Exception $e) {
            Log::error('GenkitService: rankSearchProducts failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Rank proposals berdasarkan multikriteria dengan AI reasoning.
     */
    public function rankProposals(array $proposals, array $rfqContext): array
    {
        $proposalsJson = json_encode($proposals, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $rfqJson       = json_encode($rfqContext, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $prompt = <<<PROMPT
Kamu adalah sistem evaluasi tender/RFQ untuk platform procurement B2B di Indonesia.

Data RFQ (Request for Quotation):
{$rfqJson}

Daftar Proposal dari Vendor:
{$proposalsJson}

Evaluasi dan ranking setiap proposal berdasarkan kriteria berikut:
- Harga (40%): Penawaran harga terendah mendapat skor tertinggi
- Waktu pengiriman (30%): Lead time tercepat mendapat skor tertinggi  
- Garansi (20%): Masa garansi terpanjang mendapat skor tertinggi
- Kelengkapan (10%): Proposal yang menjawab semua item RFQ

Balas dengan JSON:
{
  "rankings": [
    {
      "proposal_id": "id proposal",
      "rank": 1,
      "total_score": 87.5,
      "score_breakdown": {
        "price_score": 90,
        "delivery_score": 85,
        "warranty_score": 80,
        "completeness_score": 95
      },
      "recommendation": "singkat mengapa vendor ini direkomendasikan atau tidak",
      "strengths": ["kelebihan 1", "kelebihan 2"],
      "weaknesses": ["kelemahan 1"]
    }
  ],
  "overall_analysis": "analisis singkat kondisi tender secara keseluruhan",
  "recommended_winner_id": "proposal_id pemenang yang direkomendasikan AI"
}
PROMPT;

        try {
            return $this->askJson($prompt, 'Kamu adalah evaluator tender profesional yang objektif dan mengutamakan value for money.');
        } catch (\Exception $e) {
            Log::error('GenkitService: rankProposals failed', ['error' => $e->getMessage()]);
            return ['rankings' => [], 'overall_analysis' => 'AI analysis tidak tersedia.', 'recommended_winner_id' => null];
        }
    }

    /**
     * Bandingkan beberapa produk katalog secara komprehensif.
     */
    public function compareProducts(array $catalogues): array
    {
        $cataloguesJson = json_encode($catalogues, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $prompt = <<<PROMPT
Bandingkan produk-produk berikut dari perspektif pengadaan/procurement:

{$cataloguesJson}

Berikan analisis perbandingan komprehensif. Balas dengan JSON:
{
  "comparison_matrix": [
    {
      "catalogue_id": "id",
      "product_name": "nama produk",
      "vendor_name": "nama vendor",
      "score": 85,
      "pros": ["kelebihan 1", "kelebihan 2"],
      "cons": ["kekurangan 1"],
      "best_for": "kasus penggunaan terbaik",
      "value_rating": "Sangat Baik/Baik/Cukup/Kurang"
    }
  ],
  "recommendation": "produk mana yang paling direkomendasikan dan alasannya",
  "recommended_id": "catalogue_id produk terbaik",
  "summary": "ringkasan 2-3 kalimat perbandingan"
}
PROMPT;

        try {
            return $this->askJson($prompt, 'Kamu adalah ahli pengadaan barang yang membantu buyer membuat keputusan terbaik.');
        } catch (\Exception $e) {
            Log::error('GenkitService: compareProducts failed', ['error' => $e->getMessage()]);
            return ['comparison_matrix' => [], 'recommendation' => 'Analisis tidak tersedia.', 'recommended_id' => null, 'summary' => ''];
        }
    }

    /**
     * Analisis kata kunci trending dari platform dan perkaya dengan tren eksternal (B2B procurement).
     *
     * @param  array  $dbKeywords  [['keyword' => '...', 'count' => 42], ...]
     * @return array  [['keyword' => '...', 'count' => 42, 'trend' => 'rising|stable|new', 'category' => '...', 'ai_insight' => '...'], ...]
     */
    public function getTrendingKeywords(array $dbKeywords): array
    {
        if (empty($dbKeywords)) {
            return [];
        }

        $keywordsJson = json_encode(
            collect($dbKeywords)->map(fn($k) => [
                'keyword' => $k['keyword'],
                'count'   => $k['count'],
            ])->values()->toArray(),
            JSON_UNESCAPED_UNICODE
        );

        $prompt = <<<PROMPT
Kamu adalah analis pasar pengadaan barang/jasa (procurement) B2B Indonesia.

Berikut adalah daftar kata kunci yang paling sering dicari di platform marketplace pengadaan kami beserta frekuensinya:
{$keywordsJson}

Untuk setiap kata kunci:
1. Tentukan "trend": "rising" (tren naik di pasar saat ini), "stable" (permintaan stabil), atau "new" (produk/kategori baru yang sedang berkembang)
2. Tentukan "category": kategori produk yang paling sesuai (Electronics, Raw Materials, Equipment, Chemicals, Machinery, Tools, Spare Parts, Safety Equipment, Office Supplies, dll)
3. Berikan "ai_insight": 1 kalimat singkat mengapa produk ini banyak dicari atau relevan di pasar B2B Indonesia saat ini

Balas HANYA dengan JSON array berikut (urutan sama dengan input):
[
  {
    "keyword": "...",
    "count": 42,
    "trend": "rising",
    "category": "Electronics",
    "ai_insight": "Permintaan meningkat karena..."
  }
]
PROMPT;

        try {
            $result = $this->askJson($prompt, 'Kamu adalah analis pasar procurement B2B Indonesia yang sangat berpengalaman.');
            // askJson bisa return object dengan key 'results' atau langsung array
            return is_array($result) && isset($result[0]) ? $result : ($result['results'] ?? $dbKeywords);
        } catch (\Exception $e) {
            Log::warning('GenkitService: getTrendingKeywords failed, returning raw DB data', ['error' => $e->getMessage()]);
            // Fallback: kembalikan data DB tanpa enrichment AI
            return array_map(fn($k) => array_merge($k, [
                'trend'      => 'stable',
                'category'   => null,
                'ai_insight' => null,
            ]), $dbKeywords);
        }
    }

    /**
     * Generate draft PR (Purchase Requisition) dari prompt dan item yang ditemukan.
     */
    public function generatePrDraft(string $userPrompt, array $matchedItems): array
    {
        $itemsJson = json_encode($matchedItems, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $prompt = <<<PROMPT
User ingin membuat Purchase Requisition (PR) dengan kebutuhan berikut:
"{$userPrompt}"

Produk yang tersedia di katalog dan cocok dengan kebutuhan:
{$itemsJson}

Buatkan draft PR yang profesional. Balas dengan JSON:
{
  "title": "judul PR yang deskriptif dan profesional",
  "description": "deskripsi kebutuhan yang detail (2-4 kalimat, Bahasa Indonesia)",
  "suggested_items": [
    {
      "catalogue_id": "id dari daftar di atas",
      "qty": 1,
      "estimated_price": 0,
      "reason": "alasan singkat mengapa item ini dipilih"
    }
  ],
  "duration_days": 7,
  "priority": "Normal/Urgent/Critical",
  "notes": "catatan tambahan untuk manager"
}

Pilih hanya item yang benar-benar relevan dengan kebutuhan user.
PROMPT;

        try {
            return $this->askJson($prompt, 'Kamu adalah staf pengadaan yang membantu membuat purchase requisition yang profesional.');
        } catch (\Exception $e) {
            Log::error('GenkitService: generatePrDraft failed', ['error' => $e->getMessage()]);
            return [
                'title'           => 'PR - ' . substr($userPrompt, 0, 50),
                'description'     => $userPrompt,
                'suggested_items' => [],
                'duration_days'   => 7,
                'priority'        => 'Normal',
                'notes'           => '',
            ];
        }
    }
}
