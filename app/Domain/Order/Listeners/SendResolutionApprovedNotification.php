<?php

namespace App\Domain\Order\Listeners;

use App\Domain\Order\Events\ResolutionApproved;
use Illuminate\Notifications\DatabaseNotification;

class SendResolutionApprovedNotification
{
    public function handle(ResolutionApproved $event): void
    {
        $return = $event->return;
        $po = $return->purchaseOrder;
        
        $resolutionTypeLabel = [
            'replacement' => 'Product Replacement',
            'refund' => 'Full Refund',
            'partial_refund' => 'Partial Refund',
            'credit_note' => 'Credit Note',
        ][$return->resolution_type] ?? 'Resolution';
        
        // Notify vendor company about approval
        $return->vendorCompany->notify(
            new DatabaseNotification([
                'type' => 'resolution_approved',
                'title' => 'Return Resolution Approved',
                'message' => "Buyer has approved {$resolutionTypeLabel} for return {$return->return_number} (PO {$po->po_number}). Please proceed with the resolution.",
                'data' => [
                    'return_id' => $return->id,
                    'return_number' => $return->return_number,
                    'po_id' => $po->id,
                    'po_number' => $po->po_number,
                    'resolution_type' => $return->resolution_type,
                    'buyer_notes' => $return->buyer_response_notes,
                ],
                'action_url' => "/returns/{$return->id}",
            ])
        );
    }
}
