<?php

namespace App\Domain\Order\Actions;

use App\Domain\Order\Models\GoodsReturn;
use App\Domain\Order\Events\ResolutionProposed;
use Illuminate\Support\Facades\DB;

class ProposeReturnResolutionAction
{
    public function execute(GoodsReturn $return, array $data): GoodsReturn
    {
        return DB::transaction(function () use ($return, $data) {
            $return->update([
                'resolution_type' => $data['resolution_type'], // replacement, refund, partial_refund, credit_note
                'resolution_status' => 'proposed',
                'resolution_details' => $data['resolution_details'] ?? null,
                'vendor_proposal_notes' => $data['notes'] ?? null,
                'resolution_proposed_at' => now(),
                'resolution_proposed_by' => auth()->id(),
            ]);

            // Trigger event to notify buyer
            event(new ResolutionProposed($return));

            return $return->fresh();
        });
    }
}
