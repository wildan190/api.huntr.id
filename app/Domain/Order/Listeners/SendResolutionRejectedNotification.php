<?php

namespace App\Domain\Order\Listeners;

use App\Domain\Order\Events\ResolutionRejected;
use App\Domain\Communication\Notifications\DatabaseNotification;

class SendResolutionRejectedNotification
{
    public function handle(ResolutionRejected $event): void
    {
        $return = $event->return;
        $po = $return->purchaseOrder;
        
        // Notify vendor company about rejection
        $return->vendorCompany->notify(
            new DatabaseNotification(
                'Return Resolution Rejected',
                "Buyer has rejected your proposed resolution for return {$return->return_number} (PO {$po->po_number}). Please propose a different resolution.",
                "/returns/{$return->id}",
                null,
                [
                    'type' => 'resolution_rejected',
                    'return_id' => $return->id,
                    'return_number' => $return->return_number,
                    'po_id' => $po->id,
                    'po_number' => $po->po_number,
                    'buyer_notes' => $return->buyer_response_notes,
                ]
            )
        );
    }
}
