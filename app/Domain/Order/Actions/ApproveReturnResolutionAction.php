<?php

namespace App\Domain\Order\Actions;

use App\Domain\Order\Models\GoodsReturn;
use App\Domain\Order\Events\ResolutionApproved;
use App\Domain\Order\Events\ResolutionRejected;
use Illuminate\Support\Facades\DB;

class ApproveReturnResolutionAction
{
    public function execute(GoodsReturn $return, bool $approved, ?string $buyerNotes = null): GoodsReturn
    {
        return DB::transaction(function () use ($return, $approved, $buyerNotes) {
            $return->update([
                'resolution_status' => $approved ? 'buyer_approved' : 'rejected',
                'buyer_response_notes' => $buyerNotes,
                'resolution_approved_at' => $approved ? now() : null,
                'resolution_approved_by' => auth()->id(),
                'status' => $approved ? 'processing_resolution' : 'pending_resolution',
            ]);

            // Trigger appropriate event
            if ($approved) {
                event(new ResolutionApproved($return));
                
                // If resolution is refund or credit note, create debit note automatically
                if (in_array($return->resolution_type, ['refund', 'partial_refund', 'credit_note'])) {
                    $this->createDebitNote($return);
                }
            } else {
                event(new ResolutionRejected($return));
            }

            return $return->fresh();
        });
    }

    protected function createDebitNote(GoodsReturn $return): void
    {
        $action = app(\App\Domain\Order\Actions\CreateDebitNoteAction::class);
        
        $action->execute([
            'return_id' => $return->id,
            'po_id' => $return->po_id,
            'buyer_company_id' => $return->buyer_company_id,
            'vendor_company_id' => $return->vendor_company_id,
            'debit_note_type' => $return->resolution_type === 'credit_note' ? 'credit_note' : 'return_refund',
            'reason' => 'Automatic debit note for approved return resolution',
            'items' => $return->items,
            'subtotal' => $return->total_return_value,
            'total_amount' => $return->total_return_value,
        ]);
    }
}
