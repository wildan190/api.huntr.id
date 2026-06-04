<?php

namespace App\Domain\Negotiation\Actions;

use App\Domain\Negotiation\Models\Negotiation;
use App\Domain\Communication\Actions\BroadcastWebsocketNotificationAction;
use App\Domain\Proposal\Models\ProposalItem;
use Illuminate\Support\Facades\DB;

class RespondToNegotiationAction
{
    public function __construct(
        protected BroadcastWebsocketNotificationAction $broadcastAction
    ) {}

    public function execute(Negotiation $negotiation, string $status, ?string $vendorRemarks = null): Negotiation
    {
        return DB::transaction(function () use ($negotiation, $status, $vendorRemarks) {
            $negotiation->update([
                'status' => $status,
                'vendor_remarks' => $vendorRemarks,
            ]);

            if ($status === 'accepted') {
                $proposal = $negotiation->proposal;
                $negotiationItems = $negotiation->items;
                $totalPrice = 0;

                foreach ($negotiationItems as $negItem) {
                    $totalPrice += ($negItem->negotiated_price * $negItem->negotiated_qty);

                    if ($negItem->proposal_item_id) {
                        $pItem = ProposalItem::find($negItem->proposal_item_id);
                        if ($pItem) {
                            $pItem->update(['price_offer' => $negItem->negotiated_price]);
                        } else {
                            // Fallback: try to find by rfq_item_id if the ID sent was actually an rfq_item_id
                            $pItemFallback = ProposalItem::where('proposal_id', $proposal->id)
                                ->where('rfq_item_id', $negItem->proposal_item_id)
                                ->first();
                            if ($pItemFallback) {
                                $pItemFallback->update(['price_offer' => $negItem->negotiated_price]);
                                // Correct the negotiation item for future lookups
                                $negItem->update(['proposal_item_id' => $pItemFallback->id]);
                            }
                        }
                    }
                }

                $proposal->update([
                    'price_offer' => $totalPrice,
                    'payment_term' => $negotiation->payment_scheme ?? $proposal->payment_term,
                    'delivery_days' => $negotiation->delivery_terms ?? $proposal->delivery_days,
                ]);

                // Update associated Purchase Order if it exists
                $rfq = $proposal->rfq;
                if ($rfq) {
                    $po = \App\Domain\Order\Models\PurchaseOrder::where('rfq_id', $rfq->id)
                        ->where('vendor_id', $proposal->company_id)
                        ->first();
                    
                    if ($po) {
                        $po->update([
                            'total_amount' => $totalPrice,
                            'purchase_type' => $negotiation->payment_scheme ?? $proposal->payment_term,
                        ]);

                        // Update associated Invoice (Proforma) if it exists
                        $invoice = \App\Domain\Order\Models\Invoice::where('purchase_order_id', $po->id)
                            ->where('type', 'proforma')
                            ->first();
                        
                        if ($invoice) {
                            $invoice->update(['amount' => $totalPrice]);
                        }
                    }
                }
            }

            // Notify Buyer
            // Use buyer_id from negotiation, or fallback to the person who created the RFQ
            $targetUserId = $negotiation->buyer_id ?? $negotiation->proposal->rfq->user_id;

            if ($targetUserId) {
                $this->broadcastAction->execute(
                    "Respon Negosiasi",
                    "Vendor telah " . ($status === 'accepted' ? 'menyetujui' : 'menolak') . " negosiasi Anda untuk RFQ: " . ($negotiation->proposal->rfq->title ?? 'Untitled RFQ'),
                    'test-channel',
                    true,
                    $targetUserId,
                    "/negotiation"
                );
            }

            return $negotiation->load('proposal.rfq');
        });
    }
}
