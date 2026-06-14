<?php

namespace App\Domain\Order\Listeners;

use App\Domain\Order\Events\ReturnCreated;
use App\Domain\Communication\Notifications\DatabaseNotification;

class SendReturnCreatedNotification
{
    public function handle(ReturnCreated $event): void
    {
        $return = $event->return;
        $po = $return->purchaseOrder;
        
        // Notify vendor company about new return
        $return->vendorCompany->notify(
            new DatabaseNotification(
                'New Return Request',
                "Return request {$return->return_number} created for PO {$po->po_number}. Please propose a resolution (replacement or refund).",
                "/returns/{$return->id}",
                null,
                [
                    'type' => 'return_created',
                    'return_id' => $return->id,
                    'return_number' => $return->return_number,
                    'po_id' => $po->id,
                    'po_number' => $po->po_number,
                    'total_value' => $return->total_return_value,
                    'resolution_status' => $return->resolution_status,
                ]
            )
        );
    }
}
