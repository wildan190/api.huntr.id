<?php

namespace App\Domain\Negotiation\Actions;

use App\Domain\Negotiation\Models\Negotiation;
use App\Domain\Negotiation\Models\NegotiationItem;
use App\Domain\Proposal\Models\Proposal;
use App\Domain\Communication\Actions\BroadcastWebsocketNotificationAction;
use App\Domain\Communication\Actions\SendWhatsAppNotificationAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

class CreateNegotiationAction
{
    public function __construct(
        protected BroadcastWebsocketNotificationAction $broadcastAction,
        protected SendWhatsAppNotificationAction $whatsAppAction
    ) {}

    public function execute(Proposal $proposal, array $data): Negotiation
    {
        return DB::transaction(function () use ($proposal, $data) {
            Log::info("CreateNegotiationAction: Starting transaction", ['proposal_id' => $proposal->id]);
            
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

            // Load proposal with company and users and rfq
            $proposal->load('company.users', 'rfq');
            Log::info("CreateNegotiationAction: Loaded proposal", [
                'proposal_id' => $proposal->id,
                'company_id' => $proposal->company_id,
                'company_exists' => $proposal->company ? 'yes' : 'no',
                'users_count' => $proposal->company ? $proposal->company->users->count() : 0
            ]);
            
            // Notify Vendor company
            if ($proposal->company) {
                $proposal->company->notify(new \App\Domain\Communication\Notifications\DatabaseNotification(
                    'Negosiasi Baru',
                    "Buyer telah mengajukan negosiasi untuk Proposal Anda pada RFQ: " . ($proposal->rfq->title ?? 'Untitled RFQ'),
                    "/negotiation",
                    null,
                    ['type' => 'negotiation_started']
                ));
                Log::info("CreateNegotiationAction: Sent notification to vendor company", [
                    'company_id' => $proposal->company->id
                ]);
            }
            
            // Notify Vendor users
            if ($proposal->company && $proposal->company->users) {
                foreach ($proposal->company->users as $vendorUser) {
                    Log::info("CreateNegotiationAction: Sending notification to vendor user", [
                        'user_id' => $vendorUser->id,
                        'user_email' => $vendorUser->email
                    ]);
                    $this->broadcastAction->execute(
                        "Negosiasi Baru",
                        "Buyer telah mengajukan negosiasi untuk Proposal Anda pada RFQ: " . ($proposal->rfq->title ?? 'Untitled RFQ'),
                        "test-channel",
                        true,
                        $vendorUser->id,
                        "/negotiation",
                        ['type' => 'negotiation_started']
                    );
                }

                $this->whatsAppAction->toCompany(
                    $proposal->company,
                    "Negosiasi baru masuk untuk proposal Anda pada RFQ: " . ($proposal->rfq->title ?? 'Untitled RFQ') . ". Silakan review dan respons.",
                    false
                );
            }

            return $negotiation->load('items.proposalItem.rfqItem.catalogue');
        });
    }
}
