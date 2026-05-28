<?php

namespace App\Domain\Company\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
            
            $dummyPath = storage_path('app/dummy_npwp.json');
            $data = [
                'nama' => 'PT. Dummy Perusahaan Lokal',
                'npwp' => $npwp,
                'alamat' => 'Jl. Dummy No. 123, Jakarta Selatan, DKI Jakarta',
                'status' => 'Aktif',
                'identitas_wp' => 'Badan',
                'jenis_wp' => 'PT',
            ];

            if (file_exists($dummyPath)) {
                $json = json_decode(file_get_contents($dummyPath), true);
                if (isset($json[$npwp])) {
                    $data = $json[$npwp];
                } elseif (isset($json['default'])) {
                    $data = $json['default'];
                    $data['npwp'] = $npwp; // Keep the requested NPWP
                }
            }

            return [
                'status' => 1,
                'message' => 'Success (Bypassed from JSON)',
                'data' => $data
            ];
        }

        try {
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
                return $response->json();
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
