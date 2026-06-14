<?php

namespace App\Domain\Communication\Jobs;

use App\Domain\Communication\Events\WebsocketNotificationBroadcasted;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class BroadcastWebsocketNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $title;
    protected $body;
    protected $channel;
    protected $url;
    protected $data;

    /**
     * Create a new job instance.
     */
    public function __construct(string $title, string $body, string $channel = 'test-channel', ?string $url = null, array $data = [])
    {
        $this->title = $title;
        $this->body = $body;
        $this->channel = $channel;
        $this->url = $url;
        $this->data = $data;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            Log::info("Reverb Broadcast executing for: {$this->title}");

            event(new WebsocketNotificationBroadcasted($this->title, $this->body, $this->channel, $this->url, $this->data));

            Log::info("Reverb Broadcast finished successfully.");
        } catch (\Exception $e) {
            Log::error("Reverb Broadcast Job failed: " . $e->getMessage());
        }
    }
}
