<?php

namespace App\Domain\Payment\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MidtransService
{
    protected string $serverKey;
    protected string $baseUrl;
    protected bool $isProduction;

    public function __construct()
    {
        $key = config('services.midtrans.server_key') ?? '';
        // CRITICAL: Remove ALL whitespace, newlines, and quotes
        $this->serverKey = preg_replace('/\s+/', '', str_replace(['"', "'"], '', $key));
        
        $isProdConfig = config('services.midtrans.is_production');
        $isProdEnv = $isProdConfig === true || $isProdConfig === 'true' || $isProdConfig === 1 || $isProdConfig === '1';
        
        // Auto-detect based on key prefix (Most reliable for Midtrans)
        // Sandbox keys always start with SB-Mid-server-
        $isSandboxKey = str_starts_with($this->serverKey, 'SB-Mid-server-');
        
        if ($isSandboxKey) {
            $this->isProduction = false;
        } else {
            $this->isProduction = $isProdEnv;
        }
        
        $this->baseUrl = $this->isProduction 
            ? 'https://api.midtrans.com/v2' 
            : 'https://api.sandbox.midtrans.com/v2';
    }

    /**
     * Create Charge via Core API.
     * Support: QRIS, VA (BCA, Mandiri, BNI, BRI), Dana
     */
    public function charge(array $payload): array
    {
        if (empty($this->serverKey)) {
            throw new \Exception('Midtrans Server Key is not configured. Please check your .env.local file.');
        }

        // Basic Auth for Midtrans requires the Server Key followed by a colon, then base64 encoded.
        $authHeader = base64_encode($this->serverKey . ':');

        $response = Http::withHeaders([
                'Authorization' => 'Basic ' . $authHeader,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])
            ->post("{$this->baseUrl}/charge", $payload);

        if (!$response->successful()) {
            $status = $response->status();
            $body = $response->json();
            $msg = $body['status_message'] ?? 'Unknown error';
            $validation = $body['validation_messages'] ?? $body['validation_errors'] ?? null;
            
            Log::error('Midtrans Charge Failed', [
                'status' => $status,
                'body' => $body,
                'server_key_prefix' => substr($this->serverKey, 0, 7),
                'server_key_len' => strlen($this->serverKey),
                'url' => "{$this->baseUrl}/charge"
            ]);

            // Add debug info to the exception message if it's a 401/Unknown Merchant
            if (str_contains($msg, 'Unknown Merchant') || $status === 401) {
                $msg .= " (Key: " . substr($this->serverKey, 0, 7) . "..., Len: " . strlen($this->serverKey) . ", Env: " . ($this->isProduction ? 'PROD' : 'SANDBOX') . ")";
            }

            if ($validation) {
                $msg .= ' | Validation: ' . json_encode($validation);
            }

            throw new \Exception('Payment initiation failed: ' . $msg);
        }

        return $response->json();
    }

    /**
     * Get Transaction Status.
     */
    public function getStatus(string $orderId): array
    {
        $response = Http::withBasicAuth($this->serverKey, '')
            ->get("{$this->baseUrl}/{$orderId}/status");

        return $response->json();
    }
}
