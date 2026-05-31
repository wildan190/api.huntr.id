<?php

namespace App\Domain\Communication\Actions;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class RefreshWhatsAppTokenAction
{
    /**
     * Refresh WhatsApp token from Fonnte API
     * @return array{ok: bool, token?: string, expires_in?: int, detail?: string}
     */
    public function execute(): array
    {
        $accountToken = config('services.fonnte.account_token') ?: env('FONNTE_ACCOUNT_TOKEN');
        
        if (! $accountToken) {
            Log::error('RefreshWhatsAppTokenAction: FONNTE_ACCOUNT_TOKEN is not configured.');
            return ['ok' => false, 'detail' => 'account_token_not_configured'];
        }

        try {
            Log::info('RefreshWhatsAppTokenAction: Attempting to refresh token');

            $response = Http::timeout(15)->withHeaders([
                'Authorization' => $accountToken,
            ])->get('https://api.fonnte.com/info');

            $status = $response->status();
            $body = $response->body();
            Log::info("RefreshWhatsAppTokenAction Response: HTTP {$status}. Body: {$body}");

            $data = $response->json();
            
            if (is_array($data) && ($data['status'] ?? false) === true) {
                $deviceToken = $data['data']['device_token'] ?? null;
                
                if ($deviceToken) {
                    // Cache the token for 24 hours
                    Cache::put('fonnte_device_token', $deviceToken, now()->addHours(24));
                    
                    Log::info('RefreshWhatsAppTokenAction: Token refreshed successfully');
                    
                    return [
                        'ok' => true,
                        'token' => $deviceToken,
                        'expires_in' => 86400, // 24 hours
                        'detail' => 'token_refreshed',
                    ];
                }
            }

            return ['ok' => false, 'detail' => $data['reason'] ?? $body];
        } catch (\Exception $e) {
            Log::error('RefreshWhatsAppTokenAction: '.$e->getMessage());
            return ['ok' => false, 'detail' => $e->getMessage()];
        }
    }

    /**
     * Get valid device token, refresh if expired
     */
    public function getValidToken(): ?string
    {
        // Try to get cached token
        $cachedToken = Cache::get('fonnte_device_token');
        if ($cachedToken) {
            return $cachedToken;
        }

        // Try to refresh
        $result = $this->execute();
        if ($result['ok'] && isset($result['token'])) {
            return $result['token'];
        }

        // Fallback to env token
        return config('services.fonnte.device_token') ?: env('FONNTE_DEVICE_TOKEN');
    }
}
