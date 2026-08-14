<?php

namespace App\Domain\EFaktur\Listeners;

use App\Domain\Order\Events\BastCompletedEvent;
use Illuminate\Support\Facades\Log;

/**
 * Listener ini sengaja dinonaktifkan.
 * e-Faktur TIDAK lagi dibuat otomatis saat BAST selesai.
 * User harus memilih sendiri kode barang DJP dan menerbitkan
 * faktur secara manual melalui halaman e-Faktur → BAST Siap Faktur.
 */
class CreateEfakturOnBastCompleted
{
    public function handle(BastCompletedEvent $event): void
    {
        Log::info('CreateEfakturOnBastCompleted: skipped — manual issuance required.', [
            'bast_id' => $event->bast->id,
        ]);
        // Tidak melakukan apa-apa — user terbitkan faktur manual
    }
}
