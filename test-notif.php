<?php

use App\Domain\Auth\Models\User;
use App\Domain\Communication\Actions\BroadcastWebsocketNotificationAction;

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';

$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$broadcast = app(BroadcastWebsocketNotificationAction::class);

// Replace ID 2 with the user currently logged in to your frontend if needed
$userId = 2; 
$user = User::find($userId);

if (!$user) {
    echo "User ID {$userId} not found. Please check database.\n";
    exit(1);
}

echo "Sending 3 test notifications to User: {$user->name} (ID: {$userId})...\n";

$testNotifications = [
    [
        'title' => '📦 Stock Running Low',
        'body' => 'Attention! Stock for A4 Paper in warehouse is down to 5 reams.',
        'url' => '/catalogue'
    ],
    [
        'title' => '✅ Purchase Request Approved',
        'body' => 'Your Purchase Request #PR-2026-001 has been approved by Manager.',
        'url' => '/my-pr'
    ],
    [
        'title' => '💰 New Invoice',
        'body' => 'Vendor PT. Cannonex has sent a new invoice for PO #PO-9982.',
        'url' => '/purchase_orders'
    ]
];

foreach ($testNotifications as $index => $notif) {
    echo "Sending notif #" . ($index + 1) . ": {$notif['title']}...\n";
    
    $broadcast->execute(
        $notif['title'],
        $notif['body'],
        'test-channel',
        false, // Synchronous so it arrives immediately
        $userId,
        $notif['url']
    );
    
    // Add a small delay for nice ordering
    sleep(1);
}

echo "Done! Please check the bell icon in your frontend.\n";
