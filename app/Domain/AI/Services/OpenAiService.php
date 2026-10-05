<?php

namespace App\Domain\AI\Services;

use App\Domain\AI\Models\AiUsageLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * OpenAiService
 *
 * Wrapper OpenAI Chat Completions untuk Agentic Procurement Huntr.
 *
 * Prinsip anti-halusinasi di file ini:
 *  - AI TIDAK PERNAH menentukan harga, vendor, kode item, atau katalog_id.
 *  - Semua output AI divalidasi di server (id harus ada di kandidat, merek harus ada di teks user, dst).
 *  - Fallback tidak mengarang: bila AI gagal, hasil kosong/null yang jujur dikembalikan.
 *  - Temperature rendah per fungsi, dan JSON mode bawaan OpenAI (response_format).
 *  - Tidak ada pembagian budget rata, tidak ada skor/rating karangan.
 */
class OpenAiService
{
    private const ENDPOINT = 'https://api.openai.com/v1/chat/completions';

    private const INTENT_CATEGORIES = [
        'Electronics',
        'IT Hardware',
        'Office Supplies',
        'Industrial',
        'Safety',
        'Machinery',
        'Furniture',
        'Stationery',
        'Construction',
        'Spareparts',
        'Chemicals',
        'Software',
        'General',
    ];

    private const DEPARTMENTS = [
        'IT & Engineering',
        'General Affairs',
        'Operations',
        'HR',
        'Procurement',
        'Finance',
        'Production',
        'Maintenance',
        'Warehouse',
    ];

    private const CATALOGUE_CATEGORIES = [
        'Electronics',
        'Spareparts',
        'Construction',
        'Software',
        'Furniture',
        'Stationery',
        'Mechanical',
        'Chemicals',
        'General',
    ];

    private const CATALOGUE_UOMS = [
        'Unit',
        'Pc',
        'Set',
        'Box',
        'Pack',
        'Roll',
        'Litre',
        'Kg',
        'Meter',
        'License',
    ];

    private const GROUNDING_RULES = <<<'TXT'
ATURAN KEJUJURAN DATA (WAJIB):
- Hanya gunakan informasi yang tertulis pada data yang diberikan di prompt ini.
- JANGAN menyebut atau menebak harga, vendor, kode item, ketersediaan stok, garansi, atau lead time yang tidak ada di data.
- Jika informasi tidak tersedia, isi null (atau array kosong) dan jangan mengarang.
- Jangan menyalin nilai contoh dari skema JSON; contoh hanyalah bentuk format.
TXT;

    private string $apiKey;
    private string $model;
    private int $timeout;

    public function __construct()
    {
        $rawKey = config('ai.openai_api_key') ?: env('OPENAI_API_KEY');
        $this->apiKey = is_string($rawKey) ? $rawKey : '';
        $rawModel = config('ai.openai_model') ?: env('OPENAI_MODEL');
        $this->model = is_string($rawModel) ? $rawModel : 'gpt-4o';
        $this->timeout = (int) config('ai.timeout', 45);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Heuristik non-AI (deterministik)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Ekstrak TOTAL budget dari teks. Angka hanya diterima bila didahului kata konteks
     * (budget/anggaran/pagu/Rp/...) dan tidak diikuti penanda "per unit".
     */
    public function extractBudgetFromText(string $text): ?float
    {
        $lower = mb_strtolower($text);

        $pattern = '/(?<![\p{L}])(?:budget|anggaran|pagu|plafon|dana|total\s+biaya|biaya|senilai|sebesar|maksimal|maks|hingga|sampai|rp\.?|idr)'
            . '[\s:=]*(?:(?:sekitar|sebesar|maksimal|maks|hingga|sampai|kurang\s+lebih)\s+)?(?:rp\.?|idr)?\s*'
            . '(\d+(?:[.,]\d+)*)(?:\s*(miliar|milyar|juta|jt|ribu|rb|k)(?![\p{L}]))?/iu';

        if (!preg_match_all($pattern, $lower, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return null;
        }

        foreach ($all as $m) {
            $end = $m[0][1] + strlen($m[0][0]);
            $after = substr($lower, $end, 25);
            if (preg_match('/per\s*(unit|pcs|buah|set|item)|\/\s*(unit|pcs|buah)|satuan|@|each/i', $after)) {
                continue; // harga per unit, bukan total budget
            }

            $value = $this->parseIdrAmount($m[1][0], $m[2][0] ?? '', 100_000);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Ekstrak item & kuantitas dari teks (fallback tanpa AI).
     * TIDAK memberi harga dan TIDAK membagi budget. $totalBudget dipertahankan hanya untuk kompatibilitas.
     */
    public function extractItemsFromText(string $text, ?float $totalBudget = null): array
    {
        $clean = preg_replace('/\b(?:saya|kami)?\s*(?:butuh|perlu|ingin|pengadaan|mencari|cari)\s+/iu', '', $text, 1);
        $clean = preg_replace('/\b(?:dengan|target|sekitar)?\s*(?:budget|anggaran|pagu|plafon)\b.*$/isu', '', (string) $clean);
        $parts = preg_split('/\s+(?:dan|&|\+)\s+|,\s+(?=\d+\s)/iu', (string) $clean) ?: [];

        $items = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '' || mb_strlen($part) < 3) {
                continue;
            }

            $qty = 1;
            $name = $part;
            if (preg_match('/^(\d+)\s*(?:unit|pcs|set|buah|kotak|box|paket|pasang)?\s+(.+)$/iu', $part, $m)) {
                $qty = (int) $m[1];
                $name = trim($m[2]);
            }

            $items[] = [
                'name' => mb_strtoupper(mb_substr($name, 0, 1)) . mb_substr($name, 1),
                'qty' => max(1, $qty),
                'uom' => 'unit',
                'detailed_specs' => null,
                'estimated_price' => 0,
                'price_status' => 'rfq_required',
            ];
        }

        return $items;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Transport
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Kirim prompt tunggal. Default temperature rendah.
     */
    public function ask(
        string $prompt,
        string $systemInstruction = '',
        ?string $companyId = null,
        string $endpoint = 'ask',
        float $temperature = 0.2,
        bool $jsonMode = false
    ): string {
        $messages = [];
        if ($systemInstruction !== '') {
            $messages[] = ['role' => 'system', 'content' => $systemInstruction];
        }
        $messages[] = ['role' => 'user', 'content' => $prompt];

        return $this->request($messages, $temperature, $jsonMode, $companyId, $endpoint);
    }

    /**
     * Percakapan multi-turn. Aturan kejujuran data selalu ditambahkan ke system prompt.
     */
    public function chat(
        array $messages,
        string $systemInstruction = '',
        ?string $companyId = null,
        string $endpoint = 'chat',
        float $temperature = 0.3
    ): string {
        $all = [
            [
                'role' => 'system',
                'content' => trim($systemInstruction . "\n\n" . self::GROUNDING_RULES),
            ]
        ];

        foreach ($messages as $msg) {
            $role = $msg['role'] ?? 'user';
            if (!in_array($role, ['user', 'assistant'], true)) {
                $role = 'user'; // jangan izinkan klien menyuntik role system
            }
            $all[] = ['role' => $role, 'content' => (string) ($msg['content'] ?? '')];
        }

        return $this->request($all, $temperature, false, $companyId, $endpoint);
    }

    /**
     * Minta objek JSON (JSON mode OpenAI). Mengembalikan [] jika gagal diparse.
     */
    public function askJson(
        string $prompt,
        string $systemInstruction = '',
        ?string $companyId = null,
        string $endpoint = 'askJson',
        float $temperature = 0.0
    ): array {
        $raw = $this->ask(
            $prompt . "\n\nBalas HANYA dengan satu objek JSON valid.",
            $systemInstruction,
            $companyId,
            $endpoint,
            $temperature,
            true
        );

        $decoded = json_decode(trim($raw), true);

        if (!is_array($decoded)) {
            // Cadangan: buang pagar markdown jika ada. Tidak ada "perbaikan" regex yang memotong JSON.
            $stripped = trim(preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $raw));
            $decoded = json_decode($stripped, true);
        }

        if (!is_array($decoded)) {
            Log::warning('OpenAiService: askJson gagal parse JSON', ['endpoint' => $endpoint]);
            return [];
        }

        return $decoded;
    }

    private function request(array $messages, float $temperature, bool $jsonMode, ?string $companyId, string $endpoint): string
    {
        if ($this->apiKey === '') {
            Log::warning('OpenAiService: OpenAI API key is missing.');
            throw new \RuntimeException('OpenAI API Key belum terkonfigurasi.');
        }

        $payload = [
            'model' => $this->model,
            'messages' => $messages,
            'temperature' => $temperature,
        ];
        if ($jsonMode) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])
                ->timeout($this->timeout)
                ->post(self::ENDPOINT, $payload);

            if ($response->failed()) {
                Log::error('OpenAiService API error', [
                    'endpoint' => $endpoint,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                throw new \RuntimeException('OpenAI API Error: ' . $response->status());
            }

            $data = $response->json();
            $this->trackUsage($data['usage'] ?? [], $endpoint, $companyId);

            return (string) ($data['choices'][0]['message']['content'] ?? '');
        } catch (\Exception $e) {
            Log::error('OpenAiService Exception', ['endpoint' => $endpoint, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Intent
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Ekstrak kebutuhan pengadaan. Hasil AI dinormalisasi & diverifikasi terhadap teks user.
     */
    public function extractSearchIntent(string $userQuery): array
    {
        $categories = implode(' | ', self::INTENT_CATEGORIES);
        $departments = implode(' | ', self::DEPARTMENTS);

        $prompt = <<<PROMPT
Ekstrak kebutuhan pengadaan dari permintaan berikut. HANYA ambil informasi yang tertulis eksplisit.

Permintaan user: "{$userQuery}"

Aturan:
- "brand" (level atas maupun per item) hanya boleh diisi jika merek itu tertulis pada permintaan; selain itu null.
- "quantity": angka yang tertulis pada permintaan; jika tidak ada, 1.
- "spec_requirements": hanya spesifikasi yang disebut user; jika tidak ada, null.
- "budget_hint_idr": hanya jika user menyebut harga/anggaran untuk item tersebut; selain itu null.
- "category" harus salah satu dari: {$categories}; jika tidak yakin, null.
- "department" harus salah satu dari: {$departments}; jika tidak yakin, null.
- "keywords": kata kunci yang diambil dari permintaan user (jangan menambah kata baru).
- Jangan menambah item yang tidak disebut user.

Skema JSON:
{
  "keywords": ["..."],
  "category": null,
  "brand": null,
  "target_items": [
    {"name": "nama item", "brand": null, "spec_requirements": null, "quantity": 1, "uom": "unit", "budget_hint_idr": null}
  ],
  "department": null,
  "urgency": "Normal | Urgent | Critical",
  "is_comparison": false
}
PROMPT;

        try {
            $raw = $this->askJson(
                $prompt,
                'Kamu adalah ekstraktor kebutuhan pengadaan. Tugasmu hanya menyalin dan merapikan informasi dari teks user.' . "\n\n" . self::GROUNDING_RULES,
                null,
                'extractSearchIntent',
                0.0
            );

            if (!empty($raw)) {
                $intent = $this->normalizeIntent($raw, $userQuery);
                if (!empty($intent['target_items']) || !empty($intent['keywords'])) {
                    return $intent;
                }
            }
        } catch (\Exception $e) {
            Log::warning('OpenAiService: extractSearchIntent fallback', ['error' => $e->getMessage()]);
        }

        return $this->fallbackIntent($userQuery);
    }

    private function normalizeIntent(array $raw, string $query): array
    {
        $queryLower = mb_strtolower($query);

        // keywords: hanya yang benar-benar berasal dari teks user
        $keywords = [];
        foreach ((array) ($raw['keywords'] ?? []) as $kw) {
            $kw = trim((string) $kw);
            if ($kw === '' || !$this->containsOnlySourceWords($kw, $query)) {
                continue;
            }
            $keywords[] = $kw;
        }
        $keywords = array_values(array_unique($keywords));

        // target_items
        $targets = [];
        foreach ((array) ($raw['target_items'] ?? []) as $t) {
            $name = $this->cleanString($t['name'] ?? null);
            if ($name === null || !$this->containsOnlySourceWords($name, $query)) {
                continue;
            }

            $specifications = $this->cleanString($t['spec_requirements'] ?? null);
            if ($specifications !== null && !$this->containsOnlySourceWords($specifications, $query)) {
                $specifications = null;
            }

            $quantity = $this->explicitQuantity($query, $name) ?? 1;

            $hint = null;
            if (isset($t['budget_hint_idr']) && is_numeric($t['budget_hint_idr']) && (float) $t['budget_hint_idr'] > 0) {
                $hint = $this->moneyAppearsInText((float) $t['budget_hint_idr'], $query)
                    ? (float) $t['budget_hint_idr']
                    : null;
            }

            $targets[] = [
                'name' => $name,
                'brand' => $this->verifyBrand($t['brand'] ?? null, $query),
                'spec_requirements' => $specifications,
                'quantity' => $quantity,
                'uom' => $this->cleanString($t['uom'] ?? null) ?? 'unit',
                'budget_hint_idr' => $hint,
            ];
        }

        $category = $raw['category'] ?? null;
        $category = in_array($category, self::INTENT_CATEGORIES, true) ? $category : null;

        $department = $raw['department'] ?? null;
        $department = in_array($department, self::DEPARTMENTS, true) ? $department : null;

        $urgency = $raw['urgency'] ?? 'Normal';
        $urgency = in_array($urgency, ['Normal', 'Urgent', 'Critical'], true) ? $urgency : 'Normal';

        $names = array_column($targets, 'name');

        return [
            'keywords' => $keywords,
            'category' => $category,
            'brand' => $this->verifyBrand($raw['brand'] ?? null, $query),
            'target_items' => $targets,
            // Budget total HANYA dari regex deterministik, tidak pernah dari AI.
            'estimated_total_budget_idr' => $this->extractBudgetFromText($query),
            'department' => $department,
            'urgency' => $urgency,
            // Ringkasan disusun dari item hasil ekstraksi, bukan teks bebas AI.
            'ai_summary' => !empty($names) ? 'Pengadaan ' . implode(', ', $names) : 'Pengadaan: ' . $query,
            'is_comparison' => (bool) ($raw['is_comparison'] ?? false)
                || count($targets) >= 2
                || str_contains($queryLower, 'bandingkan')
                || str_contains($queryLower, 'compare'),
        ];
    }

    private function fallbackIntent(string $userQuery): array
    {
        $budget = $this->extractBudgetFromText($userQuery);
        $items = $this->extractItemsFromText($userQuery);
        $lower = mb_strtolower($userQuery);

        $keywords = array_values(array_unique(array_filter(
            preg_split('/\s+/u', trim(preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $userQuery))) ?: [],
            fn($w) => mb_strlen($w) > 2
        )));

        return [
            'keywords' => $keywords,
            'category' => null,
            'brand' => null,
            'target_items' => array_map(fn($it) => [
                'name' => $it['name'],
                'brand' => null,
                'spec_requirements' => null,
                'quantity' => $it['qty'],
                'uom' => $it['uom'],
                'budget_hint_idr' => null,
            ], $items),
            'estimated_total_budget_idr' => $budget,
            'department' => null,
            'urgency' => 'Normal',
            'ai_summary' => 'Pengadaan: ' . $userQuery,
            'is_comparison' => count($items) >= 2 || str_contains($lower, 'bandingkan') || str_contains($lower, 'compare'),
        ];
    }

    private function containsOnlySourceWords(string $candidate, string $source): bool
    {
        $candidateWords = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($candidate), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $sourceWords = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($source), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return !empty($candidateWords) && empty(array_diff($candidateWords, $sourceWords));
    }

    private function explicitQuantity(string $query, string $name): ?int
    {
        $normalizedQuery = trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($query)));
        $normalizedName = trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($name)));
        if ($normalizedName === '') {
            return null;
        }

        $namePattern = preg_quote($normalizedName, '/');
        if (preg_match(
            '/(?<![\p{L}\p{N}])(\d+)\s+(?:(?:unit|units|pcs|pc|set|buah|kotak|box|paket|pasang)\s+)?'
            . $namePattern . '(?![\p{L}\p{N}])/u',
            $normalizedQuery,
            $matches
        )) {
            return max(1, (int) $matches[1]);
        }

        if (preg_match(
            '/' . $namePattern . '\s+(?:sebanyak|qty|quantity|jumlah)\s+(\d+)(?![\p{L}\p{N}])/u',
            $normalizedQuery,
            $matches
        )) {
            return max(1, (int) $matches[1]);
        }

        return null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Ranking katalog
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Nilai kesesuaian produk katalog. Setiap kandidat SELALU mendapat satu baris hasil.
     * Skor dihitung di server dari is_match & missing_specs (bukan skor karangan AI).
     * Jika AI gagal: semua is_match = false.
     */
    public function rankSearchProducts(string $userQuery, array $products, ?string $companyId = null, array $intent = []): array
    {
        if (empty($products)) {
            return [];
        }

        $candidates = [];
        foreach ($products as $p) {
            $candidates[] = [
                'id' => $p['id'],
                'name' => $p['name'] ?? null,
                'category' => $p['category'] ?? null,
                'brand' => $p['brand'] ?? null,
                'specifications' => $p['specifications'] ?? null,
                'uom' => $p['uom'] ?? 'unit',
                'vendor' => $p['company']['name'] ?? ($p['vendor'] ?? null),
            ];
        }

        $validIds = [];
        foreach ($candidates as $c) {
            $validIds[(string) $c['id']] = $c['id'];
        }

        $candidatesJson = json_encode($candidates, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $intentJson = json_encode([
            'target_items' => $intent['target_items'] ?? null,
            'category' => $intent['category'] ?? null,
            'brand' => $intent['brand'] ?? null,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $prompt = <<<PROMPT
Kebutuhan user: "{$userQuery}"

Struktur kebutuhan:
{$intentJson}

Kandidat produk katalog:
{$candidatesJson}

Untuk SETIAP kandidat tentukan apakah produk tersebut adalah jenis barang yang dibutuhkan.

Aturan:
1. is_match = false jika KATEGORI barang berbeda dari yang diminta
   (contoh: diminta Mini PC tapi kandidat SSD, laptop, RAM, monitor, atau aksesoris; diminta Printer tapi kandidat toner/kertas).
2. is_match = false jika merek diminta eksplisit tetapi kandidat bermerek lain.
3. is_match = true hanya jika kategori cocok dan tidak ada konflik spesifikasi yang tertulis.
4. missing_specs: daftar spesifikasi yang diminta user tetapi TIDAK tertulis/terpenuhi pada data kandidat (array kosong jika semua ada).
5. fit_reason: satu kalimat berdasarkan data kandidat. Untuk penolakan sebutkan kategori yang salah.
6. Jangan menilai harga. Jangan menambah informasi yang tidak ada di data kandidat.

Skema JSON:
{
  "results": [
    {"product_id": "id kandidat", "is_match": true, "missing_specs": [], "fit_reason": "..."}
  ]
}
PROMPT;

        $byId = [];
        try {
            $response = $this->askJson(
                $prompt,
                'Kamu adalah evaluator teknis pengadaan yang ketat dan objektif. Kamu menolak produk yang berbeda kategori.' . "\n\n" . self::GROUNDING_RULES,
                $companyId,
                'rankSearchProducts',
                0.0
            );

            foreach ((array) ($response['results'] ?? []) as $row) {
                $key = (string) ($row['product_id'] ?? '');
                if (!isset($validIds[$key])) {
                    continue; // id karangan AI diabaikan
                }
                $byId[$key] = $row;
            }
        } catch (\Exception $e) {
            Log::warning('OpenAiService: rankSearchProducts gagal', ['error' => $e->getMessage()]);
        }

        $out = [];
        foreach ($validIds as $key => $origId) {
            $row = $byId[$key] ?? null;

            if ($row === null) {
                $out[] = [
                    'product_id' => $origId,
                    'is_match' => false,
                    'relevance_score' => 0,
                    'fit_reason' => 'Tidak dievaluasi oleh AI.',
                    'missing_specs' => [],
                ];
                continue;
            }

            $isMatch = ($row['is_match'] ?? false) === true;
            $missing = array_values(array_filter(array_map(
                fn($s) => $this->cleanString($s),
                (array) ($row['missing_specs'] ?? [])
            )));

            $out[] = [
                'product_id' => $origId,
                'is_match' => $isMatch,
                'relevance_score' => $isMatch ? max(50, 95 - 10 * count($missing)) : 0,
                'fit_reason' => $this->cleanString($row['fit_reason'] ?? null),
                'missing_specs' => $missing,
            ];
        }

        return $out;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Komparasi
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Bandingkan kandidat secara teknis, HANYA berdasarkan data kandidat.
     * Tidak ada harga, skor, atau rating. id dan nama produk dipulihkan dari server.
     */
    public function compareProducts(array $catalogues, ?string $userNeed = null): array
    {
        $candidates = [];
        foreach ($catalogues as $c) {
            if (!isset($c['id'])) {
                continue;
            }
            $snippets = [];
            foreach (array_slice((array) ($c['_web_results'] ?? []), 0, 3) as $r) {
                $line = trim(($r['title'] ?? '') . ' - ' . ($r['snippet'] ?? ''), ' -');
                if ($line !== '') {
                    $snippets[] = $line;
                }
            }

            $candidates[(string) $c['id']] = [
                'id' => $c['id'],
                'name' => $c['name'] ?? null,
                'brand' => $c['brand'] ?? null,
                'category' => $c['category'] ?? null,
                'specifications' => $c['specifications'] ?? null,
                'uom' => $c['uom'] ?? null,
                'vendor' => $c['vendor'] ?? null,
                'web_snippets' => $snippets,
            ];
        }

        if (count($candidates) < 2) {
            return $this->emptyComparison('Kandidat kurang dari 2, perbandingan tidak dilakukan.', false);
        }

        $needText = $userNeed ? "Kebutuhan buyer: \"{$userNeed}\"" : 'Kebutuhan buyer: tidak dirinci.';
        $json = json_encode(array_values($candidates), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $prompt = <<<PROMPT
{$needText}

Data kandidat (satu-satunya sumber informasi yang boleh dipakai):
{$json}

Bandingkan kandidat dari sisi teknis untuk kebutuhan pengadaan B2B.

Aturan:
- key_specs, pros, cons, best_for hanya boleh berasal dari field specifications, category, brand, dan web_snippets pada data di atas.
- Jika data spesifikasi tidak tertulis, tulis "tidak tersedia" pada key_specs dan biarkan pros/cons kosong. Jangan memakai pengetahuan umum tentang produk.
- Jangan menyebut harga, vendor, garansi, atau ketersediaan.
- catalogue_id harus persis salah satu id kandidat.
- winner_id harus salah satu id kandidat, atau null jika data tidak cukup untuk memilih.
- spec_table: key pada "values" adalah id kandidat.

Skema JSON:
{
  "comparison_matrix": [
    {"catalogue_id": "id", "key_specs": "...", "pros": [], "cons": [], "best_for": null}
  ],
  "winner_id": null,
  "winner_reason": null,
  "executive_summary": "ringkasan singkat berbasis data di atas",
  "spec_table": [
    {"feature": "nama parameter", "values": {"id kandidat": "nilai"}}
  ]
}
PROMPT;

        try {
            $res = $this->askJson(
                $prompt,
                'Kamu adalah analis produk pengadaan. Kamu hanya merangkum data yang diberikan dan tidak menambah fakta.' . "\n\n" . self::GROUNDING_RULES,
                null,
                'compareProducts',
                0.1
            );

            $matrix = [];
            foreach ((array) ($res['comparison_matrix'] ?? []) as $row) {
                $id = (string) ($row['catalogue_id'] ?? '');
                if (!isset($candidates[$id])) {
                    continue;
                }
                $cand = $candidates[$id];
                $matrix[] = [
                    'catalogue_id' => $cand['id'],
                    'product_name' => $cand['name'],   // dari server, bukan dari AI
                    'vendor_name' => $cand['vendor'], // dari server
                    'key_specs' => $this->cleanString($row['key_specs'] ?? null),
                    'pros' => $this->stringList($row['pros'] ?? []),
                    'cons' => $this->stringList($row['cons'] ?? []),
                    'best_for' => $this->cleanString($row['best_for'] ?? null),
                    'score' => null,
                    'value_rating' => null,
                ];
            }

            if (empty($matrix)) {
                return $this->emptyComparison('AI tidak mengembalikan perbandingan yang valid.', true);
            }

            $winnerId = (string) ($res['winner_id'] ?? '');
            $winner = isset($candidates[$winnerId]) ? $candidates[$winnerId]['id'] : null;

            $specTable = [];
            foreach ((array) ($res['spec_table'] ?? []) as $row) {
                $feature = $this->cleanString($row['feature'] ?? null);
                if ($feature === null) {
                    continue;
                }
                $values = [];
                foreach ((array) ($row['values'] ?? []) as $vid => $val) {
                    $vid = (string) $vid;
                    if (isset($candidates[$vid])) {
                        $values[$candidates[$vid]['name'] ?? $vid] = $this->cleanString(is_scalar($val) ? (string) $val : null);
                    }
                }
                if (!empty($values)) {
                    $specTable[] = ['feature' => $feature, 'values' => $values];
                }
            }

            return [
                'comparison_matrix' => $matrix,
                'winner_id' => $winner,
                'winner_reason' => $winner !== null ? $this->cleanString($res['winner_reason'] ?? null) : null,
                'executive_summary' => $this->cleanString($res['executive_summary'] ?? null),
                'spec_table' => $specTable,
            ];
        } catch (\Exception $e) {
            Log::error('OpenAiService: compareProducts gagal', ['error' => $e->getMessage()]);
        }

        return $this->emptyComparison('Perbandingan otomatis tidak tersedia. Silakan tinjau kandidat secara manual.', true);
    }

    private function emptyComparison(string $summary, bool $error): array
    {
        return [
            'comparison_matrix' => [],
            'winner_id' => null,
            'winner_reason' => null,
            'executive_summary' => $summary,
            'spec_table' => [],
            'error' => $error,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Draft PR
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Susun draft PR. $items disusun SERVER (dari intent buyer + katalog tervalidasi);
     * AI hanya menulis teks deskripsi, justifikasi, dan catatan.
     * AI tidak menyentuh harga, merek, spesifikasi, maupun kuantitas item.
     */
    public function generatePrDraft(string $userPrompt, array $items, array $context = []): array
    {
        $itemNames = array_values(array_filter(array_map(fn($i) => $i['name'] ?? null, $items)));
        $title = !empty($itemNames)
            ? 'Pengadaan ' . implode(', ', array_slice($itemNames, 0, 2)) . (count($itemNames) > 2 ? ' dan lainnya' : '')
            : 'Purchase Requisition ' . date('Y-m-d');

        $draft = [
            'title' => $title,
            'department' => $context['department'] ?? null,
            'description' => 'Permintaan pengadaan: ' . $userPrompt,
            'business_justification' => null,
            'manager_notes' => null,
            'duration_days' => (int) config('ai.default_rfq_duration_days', 7),
            'priority' => $context['urgency'] ?? 'Normal',
            'suggested_items' => $items,
            'estimated_total_budget' => 0,
            'delivery_point_recommendation' => $context['address'] ?? null,
            'vendor_evaluation_criteria' => [],
        ];

        return $draft;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Katalog & gambar
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Autofill metadata katalog. Hasil WAJIB ditinjau manusia (needs_review = true).
     */
    public function autofillCatalogue(string $name, ?string $categoryHint = null, ?string $companyId = null): array
    {
        $categories = implode(' | ', self::CATALOGUE_CATEGORIES);
        $uoms = implode(' | ', self::CATALOGUE_UOMS);
        $hint = $categoryHint ? "Kategori yang disarankan: {$categoryHint}" : '';

        $prompt = <<<PROMPT
Nama produk: "{$name}"
{$hint}

Lengkapi data katalog berikut HANYA dari informasi yang tertulis pada nama produk dan petunjuk di atas.

Aturan:
- category: salah satu dari {$categories}.
- uom: salah satu dari {$uoms}.
- brand: hanya jika merek tertulis pada nama produk; selain itu "Generic".
- specifications: hanya spesifikasi yang tertulis pada nama produk. JANGAN menambah angka, kapasitas, material, atau fitur yang tidak tertulis. Jika tidak ada, null.
- keywords: kata kunci dari nama produk, dipisah koma.
- image_search_query: pencarian gambar bahasa Inggris berdasarkan nama produk.

Skema JSON:
{"category": "...", "brand": "...", "uom": "...", "specifications": null, "keywords": "...", "image_search_query": "..."}
PROMPT;

        $category = $categoryHint && in_array($categoryHint, self::CATALOGUE_CATEGORIES, true) ? $categoryHint : 'General';
        $result = [
            'category' => $category,
            'brand' => 'Generic',
            'uom' => 'Unit',
            'specifications' => null,
            'keywords' => mb_strtolower($name),
            'image_search_query' => $name,
            'needs_review' => true,
        ];

        try {
            $res = $this->askJson(
                $prompt,
                'Kamu adalah staf master data katalog yang teliti dan tidak menebak.' . "\n\n" . self::GROUNDING_RULES,
                $companyId,
                'autofillCatalogue',
                0.1
            );

            if (!empty($res)) {
                if (in_array($res['category'] ?? null, self::CATALOGUE_CATEGORIES, true)) {
                    $result['category'] = $res['category'];
                }
                if (in_array($res['uom'] ?? null, self::CATALOGUE_UOMS, true)) {
                    $result['uom'] = $res['uom'];
                }
                $brand = $this->cleanString($res['brand'] ?? null);
                if ($brand !== null && (strcasecmp($brand, 'Generic') === 0 || stripos($name, $brand) !== false)) {
                    $result['brand'] = $brand;
                }
                $result['specifications'] = $this->cleanString($res['specifications'] ?? null);
                $result['keywords'] = $this->cleanString($res['keywords'] ?? null) ?? $result['keywords'];
                $result['image_search_query'] = $this->cleanString($res['image_search_query'] ?? null) ?? $name;
            }
        } catch (\Exception $e) {
            Log::warning('OpenAiService: autofillCatalogue fallback', ['error' => $e->getMessage()]);
        }

        return $result;
    }

    /**
     * Generate gambar ilustrasi produk. Gambar adalah HASIL AI (is_ai_generated = true)
     * dan tidak boleh diperlakukan sebagai foto produk resmi.
     */
    public function generateProductImage(string $productName, ?string $category = null, ?string $brand = null, ?string $companyId = null): array
    {
        $brandClean = $brand && strtolower($brand) !== 'generic' ? $brand : '';

        $optimizedPrompt = "commercial product photography of {$brandClean} {$productName}, centered, studio lighting, plain clean pure white background, sharp focus, realistic photo";

        try {
            $chatGptPrompt = <<<PROMPT
Nama Produk: "{$productName}"
Kategori: "{$category}"
Brand: "{$brandClean}"

Tulis deskripsi visual bahasa Inggris singkat (1-2 kalimat) untuk foto produk katalog e-commerce (bukan kartun/lukisan).
Deskripsikan bentuk fisik umum barang di atas background putih studio. Jangan menambah logo, teks, atau fitur yang tidak disebut pada nama produk.
Akhiri dengan: "commercial product photo, centered, pure white background, sharp focus".

Balas HANYA dengan teks prompt tanpa tanda petik.
PROMPT;

            $aiPrompt = trim($this->ask(
                $chatGptPrompt,
                'You are a commercial product photographer and catalog image prompt engineer.',
                $companyId,
                'optimizeImagePrompt',
                0.3
            ));
            if ($aiPrompt !== '' && strlen($aiPrompt) > 20) {
                $optimizedPrompt = $aiPrompt;
            }
        } catch (\Exception $e) {
            Log::warning('OpenAiService: image prompt optimization fallback', ['error' => $e->getMessage()]);
        }

        $negative = 'blurry, low quality, cartoon, anime, 3d render, drawing, painting, illustration, watermark, text, signature, duplicate, distorted, fantasy, deformed';
        $encodedPrompt = urlencode($optimizedPrompt);
        $encodedNegative = urlencode($negative);

        $attempts = [
            ['url' => "https://image.pollinations.ai/prompt/{$encodedPrompt}?negative={$encodedNegative}&width=800&height=800&nologo=true&enhance=false&model=flux", 'timeout' => 35],
            ['url' => "https://image.pollinations.ai/prompt/{$encodedPrompt}?negative={$encodedNegative}&width=600&height=600&nologo=true&model=turbo", 'timeout' => 20],
        ];

        $lastError = null;
        foreach ($attempts as $attempt) {
            try {
                $res = Http::timeout($attempt['timeout'])->get($attempt['url']);
                if ($res->successful() && strlen($res->body()) > 2000) {
                    return [
                        'success' => true,
                        'b64_json' => base64_encode($res->body()),
                        'url' => $attempt['url'],
                        'is_ai_generated' => true,
                    ];
                }
            } catch (\Exception $e) {
                $lastError = $e->getMessage();
                Log::warning('OpenAiService: image generation attempt failed', ['error' => $lastError]);
            }
        }

        throw new \RuntimeException('Gagal meng-generate gambar produk AI' . ($lastError ? ': ' . $lastError : '.'));
    }

    /**
     * Teks perbandingan umum (markdown). Tidak memuat harga; berisi penafian.
     */
    public function generateComparisonText(string $userQuery): string
    {
        $prompt = <<<PROMPT
User meminta perbandingan produk berikut:
"{$userQuery}"

Buat perbandingan spesifikasi umum dalam tabel Markdown (kolom: Aspek | Produk A | Produk B).
Aturan:
- JANGAN membuat baris harga atau estimasi harga.
- Isi hanya aspek yang Anda yakin sebagai pengetahuan umum yang stabil; tulis "perlu verifikasi" jika ragu, dan "tidak diketahui" jika tidak tahu.
- Jangan menyebut tipe/seri yang tidak disebut user.
- Akhiri dengan satu kalimat bahwa ini perbandingan umum yang harus diverifikasi ke datasheet/vendor sebelum dipakai di dokumen pengadaan.
PROMPT;

        try {
            return $this->ask(
                $prompt,
                'Kamu adalah asisten pengadaan yang objektif dan jujur terhadap batas pengetahuannya.' . "\n\n" . self::GROUNDING_RULES,
                null,
                'generateComparisonText',
                0.2
            );
        } catch (\Exception $e) {
            Log::error('OpenAiService: generateComparisonText failed', ['error' => $e->getMessage()]);
            return 'Gagal memuat perbandingan produk.';
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Usage
    // ─────────────────────────────────────────────────────────────────────────

    private function trackUsage(array $usage, string $endpoint, ?string $companyId = null): void
    {
        try {
            $promptTokens = (int) ($usage['prompt_tokens'] ?? 0);
            $completionTokens = (int) ($usage['completion_tokens'] ?? 0);
            $totalTokens = (int) ($usage['total_tokens'] ?? ($promptTokens + $completionTokens));

            AiUsageLog::create([
                'company_id' => $companyId,
                'user_id' => null,
                'endpoint' => $endpoint,
                'model' => $this->model,
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'total_tokens' => $totalTokens,
                'estimated_cost_usd' => AiUsageLog::estimateCost($this->model, $promptTokens, $completionTokens),
            ]);
        } catch (\Throwable $e) {
            Log::warning('OpenAiService: trackUsage failed', ['error' => $e->getMessage()]);
        }
    }

    public function getUsageSummary(string $companyId): array
    {
        return AiUsageLog::getMonthlySummary($companyId);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function cleanString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if ($value === '' || in_array(mb_strtolower($value), ['null', 'none', 'n/a', '-', 'tidak ada'], true)) {
            return null;
        }
        return $value;
    }

    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        return array_values(array_filter(array_map(fn($v) => $this->cleanString($v), $value)));
    }

    /** Merek hanya valid jika benar-benar tertulis di teks user. */
    private function verifyBrand(mixed $brand, string $query): ?string
    {
        $brand = $this->cleanString($brand);
        if ($brand === null) {
            return null;
        }
        return $this->containsOnlySourceWords($brand, $query) ? $brand : null;
    }

    /** Nominal hasil AI hanya valid jika angka itu memang disebut di teks user. */
    private function moneyAppearsInText(float $amount, string $text): bool
    {
        foreach ($this->findMoneyValues($text) as $value) {
            if (abs($value - $amount) < 1) {
                return true;
            }
        }
        return false;
    }

    private function findMoneyValues(string $text): array
    {
        $values = [];

        if (preg_match_all('/(?<![\p{L}])(?:rp\.?|idr)\s*(\d+(?:[.,]\d+)*)(?:\s*(miliar|milyar|juta|jt|ribu|rb|k)(?![\p{L}]))?/iu', $text, $m, PREG_SET_ORDER)) {
            foreach ($m as $row) {
                $v = $this->parseIdrAmount($row[1], $row[2] ?? '', 1_000);
                if ($v !== null) {
                    $values[] = $v;
                }
            }
        }

        if (preg_match_all('/(\d+(?:[.,]\d+)*)\s*(miliar|milyar|juta|jt|ribu|rb)(?![\p{L}])/iu', $text, $m, PREG_SET_ORDER)) {
            foreach ($m as $row) {
                $v = $this->parseIdrAmount($row[1], $row[2], 1_000);
                if ($v !== null) {
                    $values[] = $v;
                }
            }
        }

        return $values;
    }

    /**
     * Parse nominal rupiah; null jika ambigu atau di bawah batas $min.
     */
    private function parseIdrAmount(string $raw, string $unit, float $min): ?float
    {
        $multiplier = match (mb_strtolower($unit)) {
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

        return ($value >= $min && $value <= 50_000_000_000) ? $value : null;
    }
}