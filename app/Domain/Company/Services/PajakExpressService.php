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
    protected string $npwp;

    protected const TOKEN_DEFAULT_TTL = 21600;
    protected const TOKEN_SAFETY_BUFFER = 300;

    public const AUTH_CACHE_KEY     = 'pajakexpress_auth_token';
    public const JWT_CACHE_KEY      = 'pajakexpress_jwt_token';

    public function __construct()
    {
        $this->baseUrl  = rtrim(config('services.pajak_express.base_url', env('PAJAK_EXPRESS_BASE_URL', 'https://nodemin.pajakexpress.id:1830')), '/');
        $this->email    = config('services.pajak_express.email', env('PAJAK_EXPRESS_EMAIL', 'dummy@ortax.org'));
        $this->password = config('services.pajak_express.password', env('PAJAK_EXPRESS_PASSWORD', 'Ortax123#'));
        $this->npwp     = config('services.pajak_express.npwp', env('PAJAK_EXPRESS_NPWP', ''));
    }

    public function verifyNpwp(string $npwp): array
    {
        $npwpClean = preg_replace('/[^0-9]/', '', $npwp);
        $cacheKey = 'pajakexpress_npwp_' . $npwpClean;

        try {
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
            Log::error("PajakExpress Service Exception: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
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

        $xToken = $this->getAuthToken($attempt > 0);

        $requestPayload = [
            'npwp' => $npwp,
            'tujuan' => 'Validasi NPWP',
        ];

        Log::info("PajakExpress Request (attempt " . ($attempt + 1) . "/{$maxAttempts}):", [
            'url' => $this->baseUrl . '/IF_CLB_059',
            'npwp' => $npwp,
            'x_token_preview' => substr($xToken, 0, 10) . '...',
        ]);

        $response = Http::asJson()
            ->acceptJson()
            ->withHeaders([
                'x-token'       => $xToken,
                'Authorization' => 'Bearer ' . $this->getJwtToken(),
            ])
            ->post($this->baseUrl . '/IF_CLB_059', $requestPayload);

        $httpStatus = $response->status();
        $bodyRaw = $response->body();
        $bodyJson = $response->json();
        $isAuthFailure = $this->isAuthFailure($httpStatus, $bodyJson ?? [], $bodyRaw);

        if ($isAuthFailure && $attempt < ($maxAttempts - 1)) {
            Log::warning("PajakExpress auth failure on verify. Forcing x-token refresh and retry...", [
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
            'request_url' => $this->baseUrl . '/IF_CLB_059',
            'auth_failure_detected' => $isAuthFailure,
            'attempts_used' => $attempt + 1,
        ]);

        $msg = 'API connection failed: ' . $httpStatus;
        if ($isAuthFailure) {
            $msg = 'PajakExpress otentikasi gagal (token invalid/expired) setelah ' . ($attempt + 1) . 'x percobaan. Silakan cek credentials di environment.';
        } elseif (is_array($bodyJson) && !empty($bodyJson['message'])) {
            $msg = (string) $bodyJson['message'];
        }

        return [
            'status' => 0,
            'message' => $msg,
            'code' => $httpStatus,
        ];
    }

    protected function isAuthFailure(int $httpStatus, array $bodyJson, string $bodyRaw): bool
    {
        if (in_array($httpStatus, [401, 403], true)) {
            return true;
        }

        if ($httpStatus >= 400) {
            $lowerBody = mb_strtolower($bodyRaw);
            $keywords = [
                'unauthorized',
                'invalid token',
                'token invalid',
                'token expired',
                'token not valid',
                'token is invalid',
                'token is expired',
                'akses ditolak',
                'authentication failed',
                'auth failed',
                'unauthenticated',
            ];
            foreach ($keywords as $k) {
                if (str_contains($lowerBody, $k)) {
                    return true;
                }
            }
            if (isset($bodyJson['status']) && is_string($bodyJson['status']) && str_contains(mb_strtolower($bodyJson['status']), 'error')) {
                $msg = mb_strtolower((string) ($bodyJson['message'] ?? ''));
                foreach ($keywords as $k) {
                    if (str_contains($msg, $k)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Full auth flow:
     * 1. POST /auth/login (multipart) → JWT
     * 2. POST /npwp/log (Authorization: Bearer JWT) → generate x-token
     * 3. GET  /npwp/log (Authorization: Bearer JWT) → ambil x-token
     * 4. Cache JWT dan x-token terpisah
     */
    protected function getAuthToken(bool $forceRefresh = false): string
    {
        $cacheKey = self::AUTH_CACHE_KEY;

        if (!$forceRefresh && Cache::has($cacheKey)) {
            $cached = Cache::get($cacheKey);
            if (isset($cached['expires_at']) && $cached['expires_at'] > now()->timestamp) {
                Log::info("PajakExpress x-token cache hit", [
                    'remaining_sec' => max(0, (int) $cached['expires_at'] - now()->timestamp),
                ]);
                return $cached['token'];
            }
        }

        // Step 1: Login → get JWT
        $jwt = $this->loginAndGetJwt();

        // Cache JWT terpisah (TTL dari exp di payload)
        $jwtPayload = json_decode(base64_decode(explode('.', $jwt)[1] ?? ''), true);
        $jwtExp     = isset($jwtPayload['exp']) ? (int) $jwtPayload['exp'] : (now()->timestamp + self::TOKEN_DEFAULT_TTL);
        $jwtTtl     = max(60, $jwtExp - now()->timestamp - self::TOKEN_SAFETY_BUFFER);
        Cache::put(self::JWT_CACHE_KEY, $jwt, now()->addSeconds($jwtTtl));

        // Step 2: Exchange JWT for x-token via /npwp/log
        $xToken = $this->fetchXToken($jwt);

        // Cache x-token 6 jam
        $nowTs    = now()->timestamp;
        $cacheTtl = self::TOKEN_DEFAULT_TTL - self::TOKEN_SAFETY_BUFFER;
        Cache::put($cacheKey, [
            'token'      => $xToken,
            'expires_at' => $nowTs + $cacheTtl,
            'issued_at'  => $nowTs,
        ], now()->addSeconds($cacheTtl));

        Log::info("PajakExpress x-token obtained and cached", [
            'cache_ttl_hours' => round($cacheTtl / 3600, 2),
            'x_token_preview' => substr($xToken, 0, 10) . '...',
        ]);

        return $xToken;
    }

    protected function getJwtToken(): string
    {
        if (Cache::has(self::JWT_CACHE_KEY)) {
            return Cache::get(self::JWT_CACHE_KEY);
        }
        return $this->loginAndGetJwt();
    }

    protected function loginAndGetJwt(): string
    {
        Log::info("PajakExpress Auth: POST /auth/login", [
            'url'   => $this->baseUrl . '/auth/login',
            'email' => $this->email,
        ]);

        $response = Http::withHeaders(['Accept' => '*/*'])
            ->asMultipart()
            ->timeout(45)
            ->connectTimeout(15)
            ->post($this->baseUrl . '/auth/login', [
                ['name' => 'email',    'contents' => $this->email],
                ['name' => 'password', 'contents' => $this->password],
            ]);

        if (!$response->successful()) {
            Log::error("PajakExpress login failed", [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            throw new \Exception("PajakExpress Auth login failed: HTTP " . $response->status() . " — " . $response->body());
        }

        $data = $response->json();
        $jwt = $this->extractTokenFromAuthResponse($data, $response->body());

        if (!$jwt) {
            throw new \Exception("PajakExpress Auth: JWT not found in login response — body_len=" . strlen($response->body()));
        }

        Log::info("PajakExpress Auth: login OK, JWT extracted");

        return $jwt;
    }

    protected function fetchXToken(string $jwt): string
    {
        Log::info("PajakExpress Auth: POST /npwp/log to set x-token");

        // Step 1: POST /npwp/log dengan NPWP akun untuk generate/set key secret
        $postResponse = Http::asJson()
            ->acceptJson()
            ->withHeaders(['Authorization' => 'Bearer ' . $jwt])
            ->timeout(30)
            ->post($this->baseUrl . '/npwp/log', [
                'npwp' => $this->npwp,
            ]);

        if (!$postResponse->successful()) {
            Log::error("PajakExpress POST /npwp/log failed", [
                'status' => $postResponse->status(),
                'body'   => $postResponse->body(),
            ]);
            throw new \Exception("PajakExpress /npwp/log (POST) failed: HTTP " . $postResponse->status() . " — " . $postResponse->body());
        }

        // Step 2: GET /npwp/log untuk ambil x-token yang sudah di-set
        Log::info("PajakExpress Auth: GET /npwp/log to fetch x-token");

        $getResponse = Http::acceptJson()
            ->withHeaders(['Authorization' => 'Bearer ' . $jwt])
            ->timeout(30)
            ->get($this->baseUrl . '/npwp/log');

        if (!$getResponse->successful()) {
            Log::error("PajakExpress GET /npwp/log failed", [
                'status' => $getResponse->status(),
                'body'   => $getResponse->body(),
            ]);
            throw new \Exception("PajakExpress /npwp/log (GET) failed: HTTP " . $getResponse->status() . " — " . $getResponse->body());
        }

        $data = $getResponse->json();

        // Response: { data: [ { token: "...", session_expired: "21600", ... } ] }
        $xToken = null;
        if (isset($data['data']) && is_array($data['data'])) {
            $entry  = $data['data'][0] ?? $data['data'];
            $xToken = $entry['token'] ?? null;
        }

        if (!$xToken || strlen($xToken) < 8) {
            throw new \Exception("PajakExpress /npwp/log: x-token not found in response — " . $getResponse->body());
        }

        Log::info("PajakExpress Auth: x-token fetched from /npwp/log", [
            'x_token_preview' => substr($xToken, 0, 10) . '...',
        ]);

        return $xToken;
    }

    protected function invalidateAuthCache(): void
    {
        if (Cache::has(self::AUTH_CACHE_KEY)) {
            Cache::forget(self::AUTH_CACHE_KEY);
        }
        if (Cache::has(self::JWT_CACHE_KEY)) {
            Cache::forget(self::JWT_CACHE_KEY);
        }
        Log::info("PajakExpress Auth cache invalidated");
    }

    protected function maskCredential(string $value): string
    {
        $len = strlen($value);
        if ($len <= 4) {
            return str_repeat('*', $len);
        }
        if ($len <= 8) {
            return $value[0] . str_repeat('*', $len - 2) . $value[$len - 1];
        }
        return substr($value, 0, 2) . str_repeat('*', $len - 5) . substr($value, -3);
    }

    protected function flattenArrayKeys($data, string $prefix, array &$result): void
    {
        if (is_array($data) || is_object($data)) {
            foreach ((array) $data as $k => $v) {
                $key = trim($prefix . '.' . $k, '.');
                if (is_array($v) || is_object($v)) {
                    $this->flattenArrayKeys($v, $key, $result);
                    $result[] = $key . '[]';
                } else {
                    $preview = is_string($v) ? (strlen($v) > 48 ? substr($v, 0, 48) . '…[len=' . strlen($v) . ']' : $v) : (is_scalar($v) ? $v : gettype($v));
                    $result[] = $key . '=' . (is_bool($preview) ? ($preview ? 'TRUE' : 'FALSE') : (string) $preview);
                }
            }
        }
    }

    protected function extractTokenFromAuthResponse($json, string $bodyRaw): ?string
    {
        if (!is_array($json)) {
            $json = [];
        }

        $explicitCandidates = [
            fn() => $json['data']['token'] ?? null,
            fn() => $json['token'] ?? null,
            fn() => $json['access_token'] ?? null,
            fn() => $json['data']['access_token'] ?? null,
            fn() => $json['result']['token'] ?? null,
            fn() => $json['result']['access_token'] ?? null,
            fn() => $json['response']['token'] ?? null,
            fn() => $json['body']['token'] ?? null,
            fn() => $json['user']['token'] ?? null,
            fn() => $json['jwt'] ?? null,
            fn() => $json['data']['jwt'] ?? null,
        ];

        foreach ($explicitCandidates as $cand) {
            $val = $cand();
            if (is_string($val) && strlen($val) > 32) {
                return $val;
            }
        }

        $jwtFromRecursive = $this->findJwtRecursive($json);
        if (is_string($jwtFromRecursive)) {
            return $jwtFromRecursive;
        }

        if (trim($bodyRaw) !== '') {
            $jwtPattern = '/"(?:token|access_token|jwt)"\s*:\s*"(eyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,})"/';
            if (preg_match($jwtPattern, $bodyRaw, $m)) {
                return $m[1];
            }

            $bareJwtPattern = '/(eyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,})/';
            if (preg_match($bareJwtPattern, $bodyRaw, $m2)) {
                return $m2[1];
            }
        }

        return null;
    }

    protected function findJwtRecursive($data, int $depth = 0, int $maxDepth = 6)
    {
        if ($depth > $maxDepth) {
            return null;
        }
        if (is_string($data)) {
            if (preg_match('/^eyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}$/', $data)) {
                return $data;
            }
            return null;
        }
        if (!is_array($data)) {
            return null;
        }
        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), ['token', 'access_token', 'accesstoken', 'jwt', 'bearer', 'id_token', 'idtoken'], true)) {
                if (is_string($value) && strlen($value) > 32) {
                    return $value;
                }
            }
            if (is_array($value) || is_object($value)) {
                $res = $this->findJwtRecursive(is_object($value) ? (array) $value : $value, $depth + 1, $maxDepth);
                if (is_string($res)) {
                    return $res;
                }
            } elseif (is_string($value)) {
                if (preg_match('/^eyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}$/', $value)) {
                    return $value;
                }
            }
        }
        return null;
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
            'status'  => $status,
            'message' => $raw['message'] ?? ($status === 1 ? 'Success' : 'Failed'),
            'code'    => $code,
            'guid'    => $raw['guid'] ?? null,
            'time'    => $raw['time'] ?? null,
            'data'    => $data,
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

        // Pastikan statusWp dan statusSpt selalu ada
        if (!isset($data['statusWp'])) {
            $data['statusWp'] = null;
        }
        if (!isset($data['statusSpt'])) {
            $data['statusSpt'] = null;
        }

        return $data;
    }

    protected function parseAddress(string $alamat): array
    {
        $result = [];

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
