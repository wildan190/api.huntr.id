<?php

namespace App\Domain\EFaktur\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PajakIoService
{
    protected string $baseUrl;
    protected string $apiKey;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.pajakio.base_url', 'https://sandbox-openapi.pajak.io'), '/');
        $this->apiKey  = config('services.pajakio.api_key', '');
    }

    /**
     * Create e-Faktur Pajak Keluaran (VAT Output) v3
     */
    public function createFaktur(array $payload): array
    {
        Log::info('PajakIoService: Creating e-Faktur', [
            'noInvoice' => $payload['noInvoice'] ?? null,
        ]);

        return $this->performRequest('POST', 'efaktur/v3/penjualan', $payload);
    }

    /**
     * Get e-Faktur detail by transactionId
     */
    public function getFakturDetail(string $transactionId): array
    {
        return $this->performRequest('GET', "efaktur/v3/penjualan/{$transactionId}", [], true);
    }

    /**
     * Get e-Faktur PDF as base64 or URL
     */
    public function getFakturPdf(string $transactionId): array
    {
        return $this->performRequest('POST', 'efaktur/v3/penjualan/get-pdf', [
            'transactionId' => $transactionId,
        ]);
    }

    /**
     * Cancel an e-Faktur by transactionId
     */
    public function cancelFaktur(string $transactionId): array
    {
        return $this->performRequest('POST', 'efaktur/v3/penjualan/cancel', [
            'transactionId' => $transactionId,
        ]);
    }

    /**
     * Get request headers with base64-encoded api key
     */
    private function getHeaders(): array
    {
        $key = $this->apiKey ?: env('PAJAKIO_TOKEN', '');
        // Pajak.io auth tokens are base64-encoded hex values
        $encoded = base64_encode($key);
        return [
            'Authorization' => $encoded,
            'Content-Type'  => 'application/json',
            'Accept'        => '*/*',
        ];
    }

    /**
     * Perform HTTP request with local mock fallback on failure or configuration bypass
     */
    private function performRequest(string $method, string $path, array $data = [], bool $isGet = false)
    {
        $bypass = env('BYPASS_PAJAKIO_EFAKTUR', false) || empty($this->apiKey);

        if ($bypass && app()->environment('local')) {
            Log::info("Pajak.io e-Faktur: Bypassed/Mocked in local development mode for {$path}");
            return $this->getMockResponse($path, $data, $isGet);
        }

        try {
            $url = "{$this->baseUrl}/" . ltrim($path, '/');
            $headers = $this->getHeaders();

            Log::info("Pajak.io e-Faktur: Sending {$method} request to {$url}");
            
            $req = Http::withHeaders($headers);
            $response = $isGet ? $req->get($url) : $req->post($url, $data);

            if ($response->successful()) {
                return $response->json();
            }

            Log::warning("Pajak.io API request failed with status " . $response->status() . ". Falling back to mock data in local environment.");
            if (app()->environment('local')) {
                return $this->getMockResponse($path, $data, $isGet);
            }

            $body = $response->json();
            $msg  = $body['message'] ?? $body['error'] ?? 'Unknown error from Pajak.io';
            throw new \Exception("Pajak.io request failed: {$msg}");

        } catch (\Exception $e) {
            Log::error("Pajak.io exception: " . $e->getMessage());
            if (app()->environment('local')) {
                Log::info("Pajak.io: Falling back to mock response in local environment");
                return $this->getMockResponse($path, $data, $isGet);
            }
            throw $e;
        }
    }

    /**
     * Provide mock responses for local sandbox simulation
     */
    private function getMockResponse(string $path, array $data, bool $isGet): array
    {
        if (str_contains($path, '/cancel')) {
            return [
                'status' => 1,
                'message' => 'Faktur berhasil dibatalkan (Simulasi)',
                'data' => [
                    'transactionId' => $data['transactionId'] ?? 'mock-tx-uuid-1234',
                    'status' => 'CANCELLED',
                ],
                'nofa' => '000.000-26.00000001',
                'transactionId' => $data['transactionId'] ?? 'mock-tx-uuid-1234',
            ];
        }

        if (str_contains($path, '/get-pdf')) {
            return [
                'status' => 1,
                'message' => 'PDF URL generated (Simulasi)',
                'data' => [
                    'pdfUrl' => 'https://www.w3.org/WAI/ER/tests/xhtml/testfiles/resources/pdf/dummy.pdf',
                    'base64' => 'JVBERi0xLjQKJ...[mock-base64-data]...',
                ],
                'pdfUrl' => 'https://www.w3.org/WAI/ER/tests/xhtml/testfiles/resources/pdf/dummy.pdf',
            ];
        }

        if ($isGet) {
            $parts = explode('/', $path);
            $txId = end($parts);
            return [
                'status' => 1,
                'message' => 'Detail faktur retrieved (Simulasi)',
                'data' => [
                    'transactionId' => $txId,
                    'nofa' => '000.000-26.98765432',
                    'status' => 'APPROVED',
                ],
                'status' => 'APPROVED',
                'nofa' => '000.000-26.98765432',
                'transactionId' => $txId,
            ];
        }

        return [
            'code' => 200,
            'message' => 'SUCCESS SENDING REQUEST TO CREATE VAT OUTPUT',
            'status' => 'OK',
            'data' => [
                'transactionId' => (string) Str::uuid(),
                'noInvoice' => $data['noInvoice'] ?? 'demosandbox openAPI CTAS 2024201-1',
                'nofa' => '000.000-26.' . rand(10000000, 99999999),
                'status' => 'APPROVED',
            ]
        ];
    }
}
