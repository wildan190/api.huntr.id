<?php

namespace App\Domain\Communication\Actions;

use App\Domain\Communication\Jobs\SendWhatsAppVerificationJob;
use App\Support\WhatsappNumber;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendWhatsAppVerificationAction
{
    /**
     * @return array{ok: bool, detail?: string, target?: string}
     */
    public function execute(string $phone, string $message, bool $queued = true): array
    {
        $normalized = WhatsappNumber::normalize($phone);

        if (! WhatsappNumber::isValid($normalized)) {
            Log::warning("SendWhatsApp: invalid phone after normalize: {$phone} -> {$normalized}");

            return ['ok' => false, 'detail' => 'invalid_phone'];
        }

        $target = WhatsappNumber::fonnteTarget($normalized);

        if ($queued) {
            Log::info("SendWhatsAppVerificationAction: Dispatching queued job for target: {$target}");
            SendWhatsAppVerificationJob::dispatch($target, $message);

            return ['ok' => true, 'target' => $target];
        }

        // Get device token with refresh capability
        $refreshAction = new RefreshWhatsAppTokenAction();
        $deviceToken = $refreshAction->getValidToken();
        
        if (! $deviceToken) {
            Log::error('SendWhatsAppVerificationAction: FONNTE_DEVICE_TOKEN is not configured.');

            return ['ok' => false, 'detail' => 'not_configured'];
        }

        try {
            Log::info("SendWhatsAppVerificationAction: Sending to {$target}");

            $response = Http::timeout(15)->withHeaders([
                'Authorization' => $deviceToken,
            ])->asForm()->post('https://api.fonnte.com/send', [
                'target' => $target,
                'message' => $message,
            ]);

            $status = $response->status();
            $body = $response->body();
            Log::info("SendWhatsAppVerificationAction Response: HTTP {$status}. Body: {$body}");

            $data = $response->json();
            if (is_array($data) && ($data['status'] ?? false) === true) {
                return [
                    'ok' => true,
                    'target' => is_array($data['target'] ?? null) ? ($data['target'][0] ?? $target) : $target,
                    'detail' => $data['detail'] ?? 'sent',
                ];
            }

            // If token expired (401), try to refresh and retry once
            if ($status === 401 && strpos($body, 'token') !== false) {
                Log::warning('SendWhatsAppVerificationAction: Token expired, attempting refresh');
                $refreshResult = $refreshAction->execute();
                
                if ($refreshResult['ok'] && isset($refreshResult['token'])) {
                    Log::info('SendWhatsAppVerificationAction: Retrying with refreshed token');
                    
                    $retryResponse = Http::timeout(15)->withHeaders([
                        'Authorization' => $refreshResult['token'],
                    ])->asForm()->post('https://api.fonnte.com/send', [
                        'target' => $target,
                        'message' => $message,
                    ]);

                    $retryData = $retryResponse->json();
                    if (is_array($retryData) && ($retryData['status'] ?? false) === true) {
                        return [
                            'ok' => true,
                            'target' => is_array($retryData['target'] ?? null) ? ($retryData['target'][0] ?? $target) : $target,
                            'detail' => $retryData['detail'] ?? 'sent',
                        ];
                    }
                }
            }

            return ['ok' => false, 'detail' => $data['reason'] ?? $body, 'target' => $target];
        } catch (\Exception $e) {
            Log::error('SendWhatsAppVerificationAction: '.$e->getMessage());

            return ['ok' => false, 'detail' => $e->getMessage(), 'target' => $target];
        }
    }
}
