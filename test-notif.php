<?php

use App\Domain\Auth\Models\User;
use App\Domain\Communication\Actions\BroadcastWebsocketNotificationAction;

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';

$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$broadcast = app(BroadcastWebsocketNotificationAction::class);

// Ganti ID 2 sesuai dengan user yang sedang login di frontend Anda jika perlu
$userId = 2; 
$user = User::find($userId);

if (!$user) {
    echo "User ID {$userId} tidak ditemukan. Silakan cek database.\n";
    exit(1);
}

echo "Mengirim 3 notifikasi test ke User: {$user->name} (ID: {$userId})...\n";

$testNotifications = [
    [
        'title' => '📦 Stok Barang Menipis',
        'body' => 'Perhatian! Stok Kertas A4 di gudang tersisa 5 rim lagi.',
        'url' => '/catalogue'
    ],
    [
        'title' => '✅ PR Disetujui',
        'body' => 'Purchase Request #PR-2026-001 Anda telah disetujui oleh Manager.',
        'url' => '/my-pr'
    ],
    [
        'title' => '💰 Invoice Baru',
        'body' => 'Vendor PT. Cannonex telah mengirimkan invoice baru untuk PO #PO-9982.',
        'url' => '/purchase_orders'
    ]
];

foreach ($testNotifications as $index => $notif) {
    echo "Mengirim notif #" . ($index + 1) . ": {$notif['title']}...\n";
    
    $broadcast->execute(
        $notif['title'],
        $notif['body'],
        'test-channel',
        false, // Synchronous agar langsung masuk
        $userId,
        $notif['url']
    );
    
    // Beri jeda sedikit agar urutan terlihat bagus
    sleep(1);
}

echo "Selesai! Silakan cek ikon lonceng di frontend Anda.\n";
