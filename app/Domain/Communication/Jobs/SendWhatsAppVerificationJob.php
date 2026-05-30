<?php

namespace App\Domain\Communication\Jobs;

use App\Support\WhatsappNumber;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendWhatsAppVerificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        protected string $phone,
        protected string $message
    ) {}

    public function handle(): void
    {
        $deviceToken = config('services.fonnte.device_token') ?: env('FONNTE_DEVICE_TOKEN');
        if (! $deviceToken) {
            Log::error('FONNTE_DEVICE_TOKEN is not configured. Unable to send WhatsApp message.');

            return;
        }

        $normalized = WhatsappNumber::normalize($this->phone);
        $target = WhatsappNumber::isValid($normalized)
            ? WhatsappNumber::fonnteTarget($normalized)
            : $this->phone;

        try {
            Log::info("Fonnte Send WhatsApp Job executing for: {$target}");

            $response = Http::timeout(15)->withHeaders([
                'Authorization' => $deviceToken,
            ])->asForm()->post('https://api.fonnte.com/send', [
                'target' => $target,
                'message' => $this->message,
            ]);

            Log::info('Fonnte Send WhatsApp Response: HTTP '.$response->status().'. Body: '.$response->body());
        } catch (\Exception $e) {
            Log::error('Fonnte Send WhatsApp Job failed: '.$e->getMessage());
        }
    }
}
