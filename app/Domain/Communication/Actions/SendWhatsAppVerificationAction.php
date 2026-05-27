<?php

namespace App\Domain\Communication\Actions;

use App\Domain\Communication\Jobs\SendWhatsAppVerificationJob;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendWhatsAppVerificationAction
{
    /**
     * Send or queue a WhatsApp verification message via Fonnte.
     *
     * @param string $phone Target phone number (e.g. 085156334793)
     * @param string $message Message content
     * @param bool $queued Whether to run asynchronously in the background via Horizon queue
     * @return array|bool Response data if sync, true if queued, false on failure
     */
    public function execute(string $phone, string $message, bool $queued = true)
    {
        if ($queued) {
            Log::info("SendWhatsAppVerificationAction: Dispatching queued job for target: {$phone}");
            SendWhatsAppVerificationJob::dispatch($phone, $message);
            return true;
        }

        $deviceToken = env('FONNTE_DEVICE_TOKEN');
        if (!$deviceToken) {
            Log::error("SendWhatsAppVerificationAction: FONNTE_DEVICE_TOKEN is not configured.");
            return false;
        }

        try {
            Log::info("SendWhatsAppVerificationAction: Executing synchronous send to target: {$phone}");

            $response = Http::withHeaders([
                'Authorization' => $deviceToken,
            ])->asForm()->post('https://api.fonnte.com/send', [
                'target' => $phone,
                'message' => $message,
                'countryCode' => '62',
                'delay' => '2',
            ]);

            $status = $response->status();
            $body = $response->body();

            Log::info("SendWhatsAppVerificationAction Response: HTTP {$status}. Body: {$body}");

            $data = $response->json();
            if (isset($data['status']) && $data['status'] === true) {
                return $data;
            }

            return false;
        } catch (\Exception $e) {
            Log::error("SendWhatsAppVerificationAction: Failed to send WhatsApp. Error: " . $e->getMessage());
            return false;
        }
    }
}
