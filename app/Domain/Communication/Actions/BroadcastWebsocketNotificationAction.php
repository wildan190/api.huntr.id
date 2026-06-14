<?php

namespace App\Domain\Communication\Actions;

use App\Domain\Communication\Events\WebsocketNotificationBroadcasted;
use App\Domain\Communication\Jobs\BroadcastWebsocketNotificationJob;
use App\Domain\Communication\Notifications\DatabaseNotification;
use App\Domain\Auth\Models\User;
use Illuminate\Support\Facades\Log;

class BroadcastWebsocketNotificationAction
{
    /**
     * Broadcast a real-time message via Reverb WebSocket and save to Database.
     *
     * @param string $title The message title
     * @param string $body The message content
     * @param string $channel Target channel name
     * @param bool $queued Whether to process asynchronously in the background via Horizon queue
     * @param int|null $userId Specific user ID to notify
     * @param string|null $url Redirect URL
     * @param array $data Extra data for the notification
     * @return bool True on success
     */
    public function execute(string $title, string $body, string $channel = 'test-channel', bool $queued = true, ?string $userId = null, ?string $url = null, array $data = []): bool
    {
        // If a specific user is targeted, save to their database notifications
        if ($userId) {
            $user = User::find($userId);
            if ($user) {
                $user->notify(new DatabaseNotification($title, $body, $url, null, $data));
            }
        }

        if ($queued) {
            Log::info("BroadcastWebsocketNotificationAction: Dispatching queued broadcast job for: {$title}");
            BroadcastWebsocketNotificationJob::dispatch($title, $body, $channel, $url, $data);
            return true;
        }

        try {
            Log::info("BroadcastWebsocketNotificationAction: Executing synchronous broadcast for: {$title}");
            event(new WebsocketNotificationBroadcasted($title, $body, $channel, $url, $data));
            return true;
        } catch (\Exception $e) {
            Log::error("BroadcastWebsocketNotificationAction Failed: " . $e->getMessage());
            return false;
        }
    }
}
