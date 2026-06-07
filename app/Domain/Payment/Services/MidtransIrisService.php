<?php

namespace App\Domain\Payment\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MidtransIrisService
{
    protected string $apiKey;
    protected string $merchantKey;
    protected string $baseUrl;
    protected bool $isProduction;

    public function __construct()
    {
        $apiKey = config('services.midtrans.iris_api_key') ?? '';
        $merchantKey = config('services.midtrans.iris_merchant_key') ?? '';

        $this->apiKey = preg_replace('/\s+/', '', str_replace(['"', "'"], '', $apiKey));
        $this->merchantKey = preg_replace('/\s+/', '', str_replace(['"', "'"], '', $merchantKey));

        $isProdConfig = config('services.midtrans.is_production');
        $this->isProduction = $isProdConfig === true || $isProdConfig === 'true' || $isProdConfig === 1 || $isProdConfig === '1';

        $this->baseUrl = $this->isProduction
            ? 'https://app.midtrans.com/iris/api/v1'
            : 'https://app.midtrans.com/iris/api/v1';
    }

    /**
     * Create a payout to a vendor's bank account.
     */
    public function createPayout(array $payload): array
    {
        if (empty($this->apiKey)) {
            throw new \Exception('Midtrans IRIS API Key is not configured.');
        }

        $authHeader = base64_encode($this->apiKey . ':');

        $response = Http::withHeaders([
            'Authorization' => 'Basic ' . $authHeader,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])
            ->post("{$this->baseUrl}/payouts", $payload);

        if (!$response->successful()) {
            $status = $response->status();
            $body = $response->json();
            $msg = $body['error_message'] ?? ($body['errors'] ?? 'Unknown IRIS error');
            if (is_array($msg)) {
                $msg = json_encode($msg);
            }

            Log::error('Midtrans IRIS Payout Failed', [
                'status' => $status,
                'body' => $body,
                'payload' => $payload
            ]);

            throw new \Exception('Disbursement failed: ' . $msg);
        }

        return $response->json();
    }
}
