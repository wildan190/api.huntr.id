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

    protected const TOKEN_DEFAULT_TTL = 21600;
    protected const TOKEN_SAFETY_BUFFER = 300;

    protected const AUTH_CACHE_KEY = 'pajakexpress_auth_token';

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

            $result = $this->callVerifyEndpoint($npwp, 0);

            if (isset($result['status']) && $result['status'] === 1 && !empty($result['data'])) {
                Cache::put($cacheKey, $result, now()->addDays(30));
            }

            return $result;

        } catch (\Exception $e) {
            Log::error("PajakExpress Service Exception: " . $e->getMessage());
            return [
                'status' => 0,
                'message' => 'Exception: ' . $e->getMessage(),
                'code' => 500
            ];
        }
    }

    protected function callVerifyEndpoint(string $npwp, int $attempt): array
    {
        $maxAttempts = 2;

        $token = $this->getAuthToken($attempt > 0);

        $requestPayload = [
            'npwp' => $npwp,
            'tujuan' => 'Validasi NPWP',
        ];

        Log::info("PajakExpress Request Debug (attempt ".($attempt + 1)."/{$maxAttempts}):", [
            'url' => $this->baseUrl . '/IF_CLB_059',
            'request_method' => 'POST',
            'request_body_format' => 'application/json',
            'request_payload' => $requestPayload,
            'request_body_json' => json_encode($requestPayload),
            'npwp' => $npwp,
            'token_preview' => substr($token, 0, 10) . '...',
            'force_refresh' => $attempt > 0,
        ]);

        $response = Http::asJson()
            ->acceptJson()
            ->withHeaders([
                'x-token' => $token,
            ])
            ->post($this->baseUrl . '/IF_CLB_059', $requestPayload);

        $httpStatus = $response->status();
        $bodyRaw = $response->body();
        $bodyJson = $response->json();
        $isAuthFailure = $this->isAuthFailure($httpStatus, $bodyJson ?? [], $bodyRaw);

        if ($isAuthFailure && $attempt < ($maxAttempts - 1)) {
            Log::warning("PajakExpress auth failure detected. Forcing token refresh and retry...", [
                'http_status' => $httpStatus,
                'attempt' => $attempt + 1,
            ]);
            $this->invalidateAuthCache();
            return $this->callVerifyEndpoint($npwp, $attempt + 1);
        }

        if ($response->successful() && !$isAuthFailure) {
            $rawData = is_array($bodyJson) ? $bodyJson : [];
            return $this->normalizeResponse($rawData);
        }

        Log::error("PajakExpress API Failed:", [
            'status' => $httpStatus,
            'body' => $bodyRaw,
            'body_json' => $bodyJson,
            'headers' => $response->headers(),
            'request_url' => $this->baseUrl . '/IF_CLB_059',
            'token_preview' => substr($token, 0, 10) . '...',
            'auth_failure_detected' => $isAuthFailure,
            'attempts_used' => $attempt + 1,
        ]);

        $msg = 'API connection failed: ' . $httpStatus;
        if ($isAuthFailure) {
            $msg = 'PajakExpress otentikasi gagal (token invalid/expired) setelah ' . ($attempt + 1) . 'x percobaan. Silakan cek credentials di environment.';
        } elseif (is_array($bodyJson) && !empty($bodyJson['message'])) {
            $msg = (string)$bodyJson['message'];
        }

        return [
            'status' => 0,
            'message' => $msg,
            'code' => $httpStatus
        ];
    }

    protected function isAuthFailure(int $httpStatus, array $bodyJson, string $bodyRaw): bool
    {
        if (in_array($httpStatus, [401, 403], true)) {
            return true;
        }

        if ($httpStatus >= 400) {
            $lowerBody = mb_strtolower($bodyRaw);
            $keywords = ['unauthorized', 'token', 'expired', 'invalid token', 'token invalid', 'token expired', 'akses ditolak', 'authentication failed', 'auth failed'];
            foreach ($keywords as $k) {
                if (str_contains($lowerBody, $k)) {
                    return true;
                }
            }
            if (isset($bodyJson['status']) && is_string($bodyJson['status']) && str_contains(mb_strtolower($bodyJson['status']), 'error')) {
                $msg = mb_strtolower((string)($bodyJson['message'] ?? ''));
                foreach ($keywords as $k) {
                    if (str_contains($msg, $k)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    protected function getAuthToken(bool $forceRefresh = false): string
    {
        $cacheKey = self::AUTH_CACHE_KEY;

        if (!$forceRefresh && Cache::has($cacheKey)) {
            $cached = Cache::get($cacheKey);
            if (isset($cached['expires_at']) && $cached['expires_at'] > now()->timestamp) {
                Log::info("PajakExpress Auth cache hit", [
                    'remaining_sec' => max(0, (int)$cached['expires_at'] - now()->timestamp),
                    'forced' => $forceRefresh ? 'yes' : 'no',
                ]);
                return $cached['token'];
            }
            Log::info("PajakExpress Auth cache exists but expired or missing expires_at. Refreshing...");
        }

        if ($forceRefresh) {
            Log::info("PajakExpress Forced auth token refresh requested");
        }

        Log::info("PajakExpress Requesting new auth token", [
            'url' => $this->baseUrl . '/auth/login',
            'email' => $this->email,
            'request_format' => 'multipart/form-data (sesuai curl working: --form)',
        ]);

        $response = Http::acceptJson()
            ->asMultipart()
            ->post($this->baseUrl . '/auth/login', [
                [
                    'name' => 'email',
                    'contents' => $this->email,
                ],
                [
                    'name' => 'password',
                    'contents' => $this->password,
                ],
            ]);

        if (!$response->successful()) {
            Log::error("PajakExpress Auth Failed:", [
                'status' => $response->status(),
                'body' => $response->body(),
                'body_json' => $response->json(),
                'request_sent_as' => 'multipart/form-data',
            ]);
            throw new \Exception("PajakExpress Auth failed: " . $response->status() . " - " . $response->body());
        }

        $data = $response->json();
        $token = $data['data']['token'] ?? $data['token'] ?? $data['access_token'] ?? null;

        if (!$token) {
            Log::error("PajakExpress Auth token not found in response", [
                'response' => $data,
                'raw_body_preview' => substr($response->body(), 0, 500),
                'available_keys_top' => is_array($data) ? array_keys($data) : null,
                'available_keys_data' => isset($data['data']) && is_array($data['data']) ? array_keys($data['data']) : null,
            ]);
            throw new \Exception("PajakExpress Auth token not found in response");
        }

        $nowTs = now()->timestamp;
        $rawTtl = null;

        if (isset($data['data']['exp']) && is_numeric($data['data']['exp'])) {
            $expTs = (int)$data['data']['exp'];
            if ($expTs > $nowTs) {
                $rawTtl = $expTs - $nowTs;
            }
        }
        if ($rawTtl === null && isset($data['exp']) && is_numeric($data['exp'])) {
            $expTs = (int)$data['exp'];
            if ($expTs > $nowTs) {
                $rawTtl = $expTs - $nowTs;
            }
        }
        if ($rawTtl === null && isset($data['expires_in']) && is_numeric($data['expires_in'])) {
            $rawTtl = (int)$data['expires_in'];
        }
        if ($rawTtl === null || $rawTtl <= 0) {
            $rawTtl = self::TOKEN_DEFAULT_TTL;
        }

        $buffer = self::TOKEN_SAFETY_BUFFER;
        if ($rawTtl <= $buffer) {
            $buffer = (int)ceil($rawTtl * 0.05);
        }
        $cacheTtl = $rawTtl - $buffer;
        $expiresAt = $nowTs + $cacheTtl;

        Cache::put($cacheKey, [
            'token' => $token,
            'expires_at' => $expiresAt,
            'issued_at' => $nowTs,
            'raw_ttl_sec' => $rawTtl,
            'exp_unix' => $data['data']['exp'] ?? null,
        ], now()->addSeconds($cacheTtl));

        Log::info("PajakExpress Auth token obtained successfully", [
            'cache_ttl_sec' => $cacheTtl,
            'cache_ttl_hours' => round($cacheTtl / 3600, 2),
            'raw_ttl_from_server_sec' => $rawTtl,
            'buffer_sec' => $buffer,
            'exp_parsed_from' => isset($data['data']['exp']) ? 'data.data.exp (absolute UNIX ts)' : (isset($data['expires_in']) ? 'expires_in' : 'default 6h'),
            'userid' => $data['data']['userid'] ?? null,
            'name' => $data['data']['name'] ?? null,
        ]);

        return $token;
    }

    protected function invalidateAuthCache(): void
    {
        $cacheKey = self::AUTH_CACHE_KEY;
        if (Cache::has($cacheKey)) {
            Cache::forget($cacheKey);
            Log::info("PajakExpress Auth cache invalidated explicitly");
        }
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
