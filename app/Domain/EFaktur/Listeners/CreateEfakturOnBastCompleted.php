<?php

namespace App\Domain\EFaktur\Listeners;

use App\Domain\EFaktur\Actions\CreateEFakturAction;
use App\Domain\Order\Events\BastCompletedEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class CreateEfakturOnBastCompleted implements ShouldQueue
{
    public function __construct(
        private readonly CreateEFakturAction $createEfakturAction
    ) {}

    public function handle(BastCompletedEvent $event): void
    {
        $bast = $event->bast;
        $po = $bast->purchaseOrder;

        if (!$po) {
            Log::warning('CreateEfakturOnBastCompleted: No PO found for BAST', ['bast_id' => $bast->id]);
            return;
        }

        // Check if e-faktur already exists
        if ($bast->efaktur) {
            Log::info('CreateEfakturOnBastCompleted: e-Faktur already exists for BAST', ['bast_id' => $bast->id]);
            return;
        }

        try {
            Log::info('CreateEfakturOnBastCompleted: Creating e-faktur', ['bast_id' => $bast->id, 'po_id' => $po->id]);
            $this->createEfakturAction->execute($bast, $po);
        } catch (\Exception $e) {
            Log::error('CreateEfakturOnBastCompleted: Failed to create e-faktur', [
                'bast_id' => $bast->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}
