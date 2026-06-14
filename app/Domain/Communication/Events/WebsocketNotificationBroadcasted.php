<?php

namespace App\Domain\Communication\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WebsocketNotificationBroadcasted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $title;
    public $body;
    public $channel;
    public $url;
    public $data;

    /**
     * Create a new event instance.
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
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel($this->channel),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'communication.websocket.broadcast';
    }
}
