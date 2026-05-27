<?php

namespace App\Domain\Communication\Jobs;

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

    protected $phone;
    protected $message;

    /**
     * Create a new job instance.
     */
    public function __construct(string $phone, string $message)
    {
        $this->phone = $phone;
        $this->message = $message;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $deviceToken = env('FONNTE_DEVICE_TOKEN');
        if (!$deviceToken) {
            Log::error("FONNTE_DEVICE_TOKEN is not configured. Unable to send WhatsApp message.");
            return;
        }

        try {
            Log::info("Fonnte Send WhatsApp Job executing for: {$this->phone}");

            $response = Http::withHeaders([
                'Authorization' => $deviceToken,
            ])->asForm()->post('https://api.fonnte.com/send', [
                'target' => $this->phone,
                'message' => $this->message,
                'countryCode' => '62',
                'delay' => '2',
            ]);

            $status = $response->status();
            $body = $response->body();

            Log::info("Fonnte Send WhatsApp Response: HTTP {$status}. Body: {$body}");
        } catch (\Exception $e) {
            Log::error("Fonnte Send WhatsApp Job failed: " . $e->getMessage());
        }
    }
}
