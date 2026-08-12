<?php

namespace App\Domain\Company\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class PajakExpressService
{
    protected string $baseUrl;
    protected string $email;
    protected string $password;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.pajak_express.base_url', env('PAJAK_EXPRESS_BASE_URL', 'https://nodemin.pajakexpress.id:1830')), '/');
        $this->email = config('services.pajak_express.email', env('PAJAK_EXPRESS_EMAIL', 'dummy@ortax.org'));
        $this->password = config('services.pajak_express.password', env('PAJAK_EXPRESS_PASSWORD', 'Ortax123#'));
    }

    public function verifyNpwp(string $npwp): array
    {
        $bypassFromAdmin = \App\Domain\Admin\Models\AdminSetting::get('bypass_npwp_verification', false);
        if ($bypassFromAdmin || env('BYPASS_NPWP_VERIFICATION', false)) {
            Log::info("PajakExpress NPWP verification bypassed", [
                'source' => $bypassFromAdmin ? 'admin_panel' : 'env_variable',
            ]);

            $npwp = trim($npwp);

            Log::info("NPWP received", [
                'npwp' => $npwp,
                'length' => strlen($npwp),
                'bytes' => bin2hex($npwp)
            ]);

            $dummyPath = storage_path('app/dummy_npwp.json');
            $data = null;

            if (file_exists($dummyPath)) {
                $jsonContent = file_get_contents($dummyPath);
                $json = json_decode($jsonContent, true);

                Log::info("Loaded dummy NPWP data", [
                    'file_exists' => true,
                    'json_valid' => $json !== null,
                    'requested_npwp' => $npwp,
                    'available_keys' => array_keys($json ?? [])
                ]);

                if ($json !== null) {
                    $stringKeys = [];
                    foreach ($json as $key => $value) {
                        $stringKeys[(string)$key] = $value;
                    }

                    if (isset($stringKeys[$npwp])) {
                        $data = $stringKeys[$npwp];
                        Log::info("Found exact NPWP match in dummy data", ['npwp' => $npwp, 'nama' => $data['nama']]);
                    } else {
                        $npwpWithoutLeadingZeros = ltrim($npwp, '0') ?: '0';
                        foreach ($stringKeys as $key => $value) {
                            if ($key !== 'default' && (ltrim($key, '0') ?: '0') === $npwpWithoutLeadingZeros) {
                                $data = $value;
                                Log::info("Found NPWP match after removing leading zeros", ['npwp' => $npwp, 'matched_key' => $key, 'nama' => $data['nama']]);
                                break;
                            }
                        }
                    }

                    if (!$data && isset($stringKeys['default'])) {
                        $data = array_merge($stringKeys['default'], ['npwp' => $npwp]);
                        Log::info("Using default entry for NPWP", ['npwp' => $npwp, 'default_nama' => $stringKeys['default']['nama']]);
                    }
                }
            }

            if (!$data) {
                $data = [
                    'nama' => 'PT. Perusahaan Lokal Bypass',
                    'npwp' => $npwp,
                    'alamat' => 'Jl. Bypass No. 0, Jakarta',
                    'statusWp' => 'VALID',
                    'statusSpt' => 'VALID',
                    'status' => 'Aktif',
                    'identitas_wp' => 'Badan',
                    'jenis_wp' => 'PT',
                    'region' => 'Indonesia',
                    'provincy_country' => 'DKI Jakarta',
                    'city' => 'Jakarta',
                    'regency' => 'Bypass',
                    'zip_code' => '10000',
                    'bank_name' => 'BNI',
                    'bank_account' => '0000000000',
                    'bank_account_name' => 'PT. Perusahaan Lokal Bypass',
                    'industry_type' => 'Other',
                    'phone' => '021-0000000',
                    'email' => 'hello@dummy-company.id'
                ];
                Log::info("Using hardcoded fallback for NPWP", ['npwp' => $npwp]);
            }

            return [
                'status' => 1,
                'message' => 'Success (From Dummy Data)',
                'data' => $data
            ];
        }

        try {
            $cacheKey = 'pajakexpress_npwp_' . preg_replace('/[^0-9]/', '', $npwp);

            if (Cache::has($cacheKey)) {
                Log::info("PajakExpress Cache hit for NPWP:", ['npwp' => $npwp]);
                return Cache::get($cacheKey);
            }

            $token = $this->getAuthToken();

            Log::info("PajakExpress Request Debug:", [
                'url' => $this->baseUrl . '/IF_CLB_059',
                'npwp' => $npwp,
                'token_preview' => substr($token, 0, 10) . '...'
            ]);

            $response = Http::withHeaders([
                'x-token' => $token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->post($this->baseUrl . '/IF_CLB_059', [
                'npwp' => $npwp,
                'tujuan' => 'Validasi NPWP',
            ]);

            if ($response->successful()) {
                $rawData = $response->json();
                $normalized = $this->normalizeResponse($rawData);

                Cache::put($cacheKey, $normalized, now()->addDays(30));
                return $normalized;
            }

            Log::error("PajakExpress API Failed:", [
                'status' => $response->status(),
                'body' => $response->body(),
                'body_json' => $response->json(),
                'headers' => $response->headers(),
                'request_url' => $this->baseUrl . '/IF_CLB_059',
                'token_preview' => substr($token, 0, 10) . '...',
            ]);
            return [
                'status' => 0,
                'message' => 'API connection failed: ' . $response->status(),
                'code' => $response->status()
            ];

        } catch (\Exception $e) {
            Log::error("PajakExpress Service Exception: " . $e->getMessage());
            return [
                'status' => 0,
                'message' => 'Exception: ' . $e->getMessage(),
                'code' => 500
            ];
        }
    }

    protected function getAuthToken(): string
    {
        $cacheKey = 'pajakexpress_auth_token';

        if (Cache::has($cacheKey)) {
            $cached = Cache::get($cacheKey);
            if (isset($cached['expires_at']) && $cached['expires_at'] > now()->timestamp) {
                Log::info("PajakExpress Auth cache hit");
                return $cached['token'];
            }
        }

        Log::info("PajakExpress Requesting new auth token", [
            'url' => $this->baseUrl . '/auth/login',
            'email' => $this->email,
        ]);

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->post($this->baseUrl . '/auth/login', [
            'email' => $this->email,
            'password' => $this->password,
        ]);

        if (!$response->successful()) {
            Log::error("PajakExpress Auth Failed:", [
                'status' => $response->status(),
                'body' => $response->body(),
                'body_json' => $response->json(),
            ]);
            throw new \Exception("PajakExpress Auth failed: " . $response->status() . " - " . $response->body());
        }

        $data = $response->json();
        $token = $data['token'] ?? $data['data']['token'] ?? $data['access_token'] ?? null;

        if (!$token) {
            Log::error("PajakExpress Auth token not found in response", ['response' => $data]);
            throw new \Exception("PajakExpress Auth token not found in response");
        }

        $ttl = isset($data['expires_in']) ? (int)$data['expires_in'] : 3600;
        $expiresAt = now()->timestamp + $ttl - 60;

        Cache::put($cacheKey, [
            'token' => $token,
            'expires_at' => $expiresAt,
        ], now()->addSeconds($ttl - 60));

        Log::info("PajakExpress Auth token obtained successfully");

        return $token;
    }

    protected function normalizeResponse(array $raw): array
    {
        $code = $raw['code'] ?? 0;
        $status = ($raw['status'] ?? '') === 'success' || $code === 1 ? 1 : 0;
        $data = $raw['data'] ?? [];

        if ($status === 1 && !empty($data)) {
            $data = $this->enrichData($data);
        }

        return [
            'status' => $status,
            'message' => $raw['message'] ?? ($status === 1 ? 'Success' : 'Failed'),
            'code' => $code,
            'guid' => $raw['guid'] ?? null,
            'time' => $raw['time'] ?? null,
            'data' => $data,
        ];
    }

    protected function enrichData(array $data): array
    {
        if (isset($data['alamat'])) {
            $parsed = $this->parseAddress($data['alamat']);
            $data = array_merge($data, $parsed);
        }

        if (isset($data['nama']) && !isset($data['company_name'])) {
            $data['company_name'] = $data['nama'];
        }

        if (!isset($data['status'])) {
            $data['status'] = $data['statusWp'] ?? 'Aktif';
        }

        return $data;
    }

    protected function parseAddress(string $alamat): array
    {
        $result = [];

        $zipMatches = [];
        if (preg_match('/\b(\d{5})\b/', $alamat, $zipMatches)) {
            $result['zip_code'] = $zipMatches[1];
        }

        $cityPatterns = [
            'Jakarta', 'Surabaya', 'Bandung', 'Medan', 'Semarang', 'Makassar',
            'Palembang', 'Tangerang', 'Depok', 'Bekasi', 'Yogyakarta',
            'Bogor', 'Malang', 'Samarinda', 'Denpasar', 'Batam', 'Padang',
            'Pekanbaru', 'Balikpapan', 'Pontianak', 'Banjarmasin',
        ];

        foreach ($cityPatterns as $city) {
            if (stripos($alamat, $city) !== false) {
                $result['city'] = $city;
                break;
            }
        }

        if (stripos($alamat, 'DKI Jakarta') !== false || stripos($alamat, 'Jakarta') !== false) {
            $result['provincy_country'] = 'DKI Jakarta';
            if (!isset($result['city'])) {
                $result['city'] = 'Jakarta';
            }
        }

        if (stripos($alamat, 'SCBD') !== false) {
            $result['regency'] = 'Kebayoran Baru';
            if (!isset($result['city'])) {
                $result['city'] = 'Jakarta Selatan';
            }
            if (!isset($result['provincy_country'])) {
                $result['provincy_country'] = 'DKI Jakarta';
            }
        }

        return $result;
    }
}
