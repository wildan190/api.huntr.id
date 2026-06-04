<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$rfq = \App\Domain\Rfq\Models\Rfq::first();
if (!$rfq) {
    echo "No RFQ found\n";
    exit;
}

$rankings = $rfq->proposals()
    ->with(['company', 'items.rfqItem.catalogue'])
    ->orderBy('price_offer', 'asc')
    ->get();

echo "Proposal Count: " . $rankings->count() . "\n";
foreach ($rankings as $p) {
    echo "Proposal ID: " . $p->id . "\n";
    echo "Item Count: " . $p->items->count() . "\n";
    foreach ($p->items as $item) {
        echo " - Item ID: " . $item->id . ", Price: " . $item->price_offer . ", Name: " . ($item->rfqItem->catalogue->name ?? 'N/A') . "\n";
    }
}
