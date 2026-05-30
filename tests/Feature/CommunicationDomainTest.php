<?php

namespace Tests\Feature;

use App\Domain\Communication\Actions\SendWhatsAppVerificationAction;
use App\Domain\Communication\Actions\BroadcastWebsocketNotificationAction;
use App\Domain\Communication\Events\WebsocketNotificationBroadcasted;
use App\Domain\Communication\Jobs\SendWhatsAppVerificationJob;
use App\Domain\Communication\Jobs\BroadcastWebsocketNotificationJob;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CommunicationDomainTest extends TestCase
{
    /**
     * Test that SendWhatsAppVerificationAction correctly dispatches SendWhatsAppVerificationJob to the queue.
     */
    public function test_whatsapp_verification_action_dispatches_queue_job(): void
    {
        Queue::fake();

        $action = new SendWhatsAppVerificationAction();
        $result = $action->execute('085156334793', 'Test queued message', true);

        $this->assertTrue($result['ok'] ?? false);
        Queue::assertPushed(SendWhatsAppVerificationJob::class);
    }

    /**
     * Test that BroadcastWebsocketNotificationAction correctly dispatches BroadcastWebsocketNotificationJob to the queue.
     */
    public function test_websocket_broadcast_action_dispatches_queue_job(): void
    {
        Queue::fake();

        $action = new BroadcastWebsocketNotificationAction();
        $result = $action->execute('Test Broadcaster', 'Broadcast queued body', 'test-channel', true);

        $this->assertTrue($result);
        Queue::assertPushed(BroadcastWebsocketNotificationJob::class);
    }

    /**
     * Test that WebsocketNotificationBroadcasted event broadcasts on the correct channel name.
     */
    public function test_websocket_event_broadcasts_on_channel(): void
    {
        $event = new WebsocketNotificationBroadcasted('Test Title', 'Test Body', 'custom-channel');

        $channels = $event->broadcastOn();
        
        $this->assertCount(1, $channels);
        $this->assertEquals('custom-channel', $channels[0]->name);
        $this->assertEquals('communication.websocket.broadcast', $event->broadcastAs());
    }

    /**
     * Test live integration with Fonnte WhatsApp API.
     */
    public function test_live_fonnte_api_connection(): void
    {
        $deviceToken = env('FONNTE_DEVICE_TOKEN');
        
        if (!$deviceToken) {
            $this->markTestSkipped('FONNTE_DEVICE_TOKEN is not configured in the .env file.');
        }

        // Hit Fonnte Device Info endpoint to verify token validity
        $response = Http::withHeaders([
            'Authorization' => $deviceToken,
        ])->post('https://api.fonnte.com/device');

        $this->assertEquals(200, $response->status());
        
        $data = $response->json();
        $this->assertArrayHasKey('status', $data);
        $this->assertTrue($data['status'], 'Fonnte device token authentication failed.');
    }

    /**
     * Test live integration with Reverb WebSocket Server.
     */
    public function test_live_reverb_server_reachable(): void
    {
        $reverbHost = env('REVERB_HOST', 'reverb');
        $reverbPort = env('REVERB_PORT', 8080);

        try {
            $response = Http::timeout(3)->get("http://{$reverbHost}:{$reverbPort}");
            // Reverb is running and responds with a status code
            $this->assertLessThan(500, $response->status());
        } catch (\Exception $e) {
            $this->fail("Unable to connect to Reverb WebSocket Server at http://{$reverbHost}:{$reverbPort}. Error: " . $e->getMessage());
        }
    }
}
