<?php

namespace App\Domain\Order\Listeners;

use App\Domain\Order\Events\ResolutionProposed;
use Illuminate\Notifications\DatabaseNotification;

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
            new DatabaseNotification([
                'type' => 'resolution_proposed',
                'title' => 'Return Resolution Proposed',
                'message' => "Vendor has proposed {$resolutionTypeLabel} for return {$return->return_number} (PO {$po->po_number}). Please review and approve/reject.",
                'data' => [
                    'return_id' => $return->id,
                    'return_number' => $return->return_number,
                    'po_id' => $po->id,
                    'po_number' => $po->po_number,
                    'resolution_type' => $return->resolution_type,
                    'resolution_details' => $return->resolution_details,
                    'vendor_notes' => $return->vendor_proposal_notes,
                ],
                'action_url' => "/returns/{$return->id}",
            ])
        );
    }
}
