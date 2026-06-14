<?php

namespace App\Domain\Order\Listeners;

use App\Domain\Order\Events\ResolutionProposed;
use App\Domain\Communication\Notifications\DatabaseNotification;

class SendResolutionProposedNotification
{
    public function handle(ResolutionProposed $event): void
    {
        $return = $event->return;
        $po = $return->purchaseOrder;
        
        $resolutionTypeLabel = [
            'replacement' => 'Product Replacement',
            'refund' => 'Full Refund',
            'partial_refund' => 'Partial Refund',
            'credit_note' => 'Credit Note',
        ][$return->resolution_type] ?? 'Resolution';
        
        // Notify buyer company about resolution proposal
        $return->buyerCompany->notify(
            new DatabaseNotification(
                'Return Resolution Proposed',
                "Vendor has proposed {$resolutionTypeLabel} for return {$return->return_number} (PO {$po->po_number}). Please review and approve/reject.",
                "/returns/{$return->id}",
                null,
                [
                    'type' => 'resolution_proposed',
                    'return_id' => $return->id,
                    'return_number' => $return->return_number,
                    'po_id' => $po->id,
                    'po_number' => $po->po_number,
                    'resolution_type' => $return->resolution_type,
                    'resolution_details' => $return->resolution_details,
                    'vendor_notes' => $return->vendor_proposal_notes,
                ]
            )
        );
    }
}
