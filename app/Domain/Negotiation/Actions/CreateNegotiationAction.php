<?php

namespace App\Domain\Negotiation\Actions;

use App\Domain\Negotiation\Models\Negotiation;
use App\Domain\Negotiation\Models\NegotiationItem;
use App\Domain\Proposal\Models\Proposal;
use App\Domain\Communication\Actions\BroadcastWebsocketNotificationAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class CreateNegotiationAction
{
    public function __construct(
        protected BroadcastWebsocketNotificationAction $broadcastAction
    ) {}

    public function execute(Proposal $proposal, array $data): Negotiation
    {
        return DB::transaction(function () use ($proposal, $data) {
            $negotiation = Negotiation::create([
                'proposal_id' => $proposal->id,
                'buyer_id' => $data['user_id'] ?? Auth::id(),
                'status' => 'pending',
                'payment_scheme' => $data['payment_scheme'] ?? null,
                'delivery_terms' => $data['delivery_terms'] ?? null,
                'buyer_remarks' => $data['buyer_remarks'] ?? null,
            ]);

            if (isset($data['items']) && is_array($data['items'])) {
                foreach ($data['items'] as $item) {
                    NegotiationItem::create([
                        'negotiation_id' => $negotiation->id,
                        'proposal_item_id' => $item['proposal_item_id'],
                        'negotiated_price' => $item['negotiated_price'],
                        'negotiated_qty' => $item['negotiated_qty'],
                    ]);
                }
            }

            // Notify Vendor users
            if ($proposal->company && $proposal->company->users) {
                foreach ($proposal->company->users as $vendorUser) {
                    $this->broadcastAction->execute(
                        "Negosiasi Baru",
                        "Buyer telah mengajukan negosiasi untuk Proposal Anda pada RFQ: " . ($proposal->rfq->title ?? 'Untitled RFQ'),
                        'test-channel',
                        true,
                        $vendorUser->id,
                        "/proposals"
                    );
                }
            }

            return $negotiation->load('items.proposalItem.rfqItem.catalogue');
        });
    }
}
