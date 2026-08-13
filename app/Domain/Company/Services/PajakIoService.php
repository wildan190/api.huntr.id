<?php

namespace App\Domain\Company\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class PajakIoService
{
    protected string $token;
    protected string $baseUrl;

    public function __construct()
    {
        $this->token = config('services.pajakio.token', env('PAJAKIO_TOKEN'));
        $this->baseUrl = config('services.pajakio.base_url', env('PAJAKIO_BASE_URL', 'https://api.pajak.io'));
    }

    /**
     * Verify NPWP using Pajak.io API.
     *
     * @param string $npwp
     * @return array
     */
    public function verifyNpwp(string $npwp): array
    {
        try {
            $cacheKey = 'pajakio_npwp_' . preg_replace('/[^0-9]/', '', $npwp);

            if (Cache::has($cacheKey)) {
                Log::info("Pajak.io Cache hit for NPWP:", ['npwp' => $npwp]);
                return Cache::get($cacheKey);
            }

            Log::info("Pajak.io Request Debug:", [
                'url' => $this->baseUrl . '/vswp/v2/verify/npwp',
                'npwp' => $npwp,
                'token_preview' => substr($this->token, 0, 10) . '...'
            ]);

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->post($this->baseUrl . '/vswp/v2/verify/npwp', [
                'npwp' => $npwp,
                'tujuan' => 'Memverifikasi validitas identitas wajib pajak',
            ]);

            if ($response->successful()) {
                $data = $response->json();
                Cache::put($cacheKey, $data, now()->addDays(30));
                return $data;
            }

            Log::error("Pajak.io API Failed:", [
                'status' => $response->status(),
                'body' => $response->body(),
                'body_json' => $response->json(),
                'headers' => $response->headers(),
                'request_url' => $this->baseUrl . '/vswp/v2/verify/npwp',
                'token_preview' => substr($this->token, 0, 10) . '...',
            ]);
            return [
                'status' => 0,
                'message' => 'API connection failed: ' . $response->status(),
                'code' => $response->status()
            ];

        } catch (\Exception $e) {
            Log::error("Pajak.io Service Exception: " . $e->getMessage());
            return [
                'status' => 0,
                'message' => 'Exception: ' . $e->getMessage(),
                'code' => 500
            ];
        }
    }
}
