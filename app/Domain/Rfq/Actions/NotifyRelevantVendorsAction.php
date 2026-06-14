<?php

namespace App\Domain\Rfq\Actions;

use App\Domain\Rfq\Models\Rfq;
use App\Domain\Company\Models\Company;
use App\Domain\Communication\Actions\BroadcastWebsocketNotificationAction;

class NotifyRelevantVendorsAction
{
    public function __construct(
        private readonly BroadcastWebsocketNotificationAction $broadcastAction
    ) {}

    /**
     * Find and notify vendors who have products relevant to this RFQ.
     */
    public function execute(Rfq $rfq): void
    {
        // 1. Identify categories from RFQ items
        $categories = $rfq->items()
            ->with('catalogue')
            ->get()
            ->pluck('catalogue.category')
            ->filter()
            ->unique()
            ->toArray();

        if (empty($categories)) {
            return;
        }

        // 2. Find vendors who have products in these categories
        $relevantVendors = Company::where('type', 'vendor')
            ->whereHas('catalogues', function ($query) use ($categories) {
                $query->whereIn('category', $categories);
            })
            ->with('users')
            ->get();

        // 3. Notify all users belonging to these relevant vendors
        foreach ($relevantVendors as $vendor) {
            $vendorUserIds = collect($vendor->users->pluck('id'))->push($vendor->owner_id)->unique()->filter();
            foreach ($vendorUserIds as $vendorUserId) {
                $this->broadcastAction->execute(
                    "New Relevant RFQ Published",
                    "A new RFQ '{$rfq->title}' matches your product categories. Check it out and submit a bid!",
                    'vendor-channel',
                    true,
                    $vendorUserId,
                    "/rfq/{$rfq->id}",
                    ['type' => 'rfq_published']
                );
            }
        }
    }
}
