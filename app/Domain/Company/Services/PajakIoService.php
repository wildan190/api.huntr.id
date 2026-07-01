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
        // Bypass for local development if enabled in .env
        if (env('BYPASS_NPWP_VERIFICATION', false)) {
            Log::info("Pajak.io NPWP verification bypassed (Local Development Mode)");
            
            // Trim and normalize NPWP
            $npwp = trim($npwp);
            
            Log::info("NPWP received", [
                'npwp' => $npwp,
                'length' => strlen($npwp),
                'bytes' => bin2hex($npwp)
            ]);
            
            $dummyPath = storage_path('app/dummy_npwp.json');
            $data = null;
            
            // Try to load dummy data from JSON file
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
                    // Convert all keys to strings for comparison (JSON parsing may convert numeric keys to integers)
                    $stringKeys = [];
                    foreach ($json as $key => $value) {
                        $stringKeys[(string)$key] = $value;
                    }
                    
                    // Try exact match first
                    if (isset($stringKeys[$npwp])) {
                        $data = $stringKeys[$npwp];
                        Log::info("Found exact NPWP match in dummy data", ['npwp' => $npwp, 'nama' => $data['nama']]);
                    }
                    // Try matching all keys - check if any key matches when leading zeros are removed
                    else {
                        $npwpWithoutLeadingZeros = ltrim($npwp, '0') ?: '0'; // Handle case where NPWP is all zeros
                        foreach ($stringKeys as $key => $value) {
                            if ($key !== 'default' && (ltrim($key, '0') ?: '0') === $npwpWithoutLeadingZeros) {
                                $data = $value;
                                Log::info("Found NPWP match after removing leading zeros", ['npwp' => $npwp, 'matched_key' => $key, 'nama' => $data['nama']]);
                                break;
                            }
                        }
                    }
                    
                    // If still not found, use default
                    if (!$data && isset($stringKeys['default'])) {
                        $data = array_merge($stringKeys['default'], ['npwp' => $npwp]);
                        Log::info("Using default entry for NPWP", ['npwp' => $npwp, 'default_nama' => $stringKeys['default']['nama']]);
                    }
                }
            }
            
            // Fallback if no data found
            if (!$data) {
                $data = [
                    'nama' => 'PT. Perusahaan Lokal Bypass',
                    'npwp' => $npwp,
                    'alamat' => 'Jl. Bypass No. 0, Jakarta',
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
            $cacheKey = 'pajakio_npwp_' . preg_replace('/[^0-9]/', '', $npwp);

            if (Cache::has($cacheKey)) {
                Log::info("Pajak.io Cache hit for NPWP:", ['npwp' => $npwp]);
                return Cache::get($cacheKey);
            }

            // Encode token to Base64 as per documentation
            $encodedToken = base64_encode($this->token);

            Log::info("Pajak.io Request Debug:", [
                'url' => $this->baseUrl . '/vswp/v2/verify/npwp',
                'npwp' => $npwp,
                'encoded_token_preview' => substr($encodedToken, 0, 10) . '...'
            ]);

            $response = Http::withHeaders([
                'Authorization' => $encodedToken,
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
                'headers' => $response->headers()
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
