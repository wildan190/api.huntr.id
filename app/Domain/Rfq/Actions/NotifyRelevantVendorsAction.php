<?php

namespace App\Domain\Rfq\Actions;

use App\Support\KeywordNormalizer;
use App\Domain\Rfq\Models\Rfq;
use App\Domain\Company\Models\Company;
use App\Domain\Communication\Actions\BroadcastWebsocketNotificationAction;
use App\Domain\Communication\Actions\SendWhatsAppNotificationAction;

class NotifyRelevantVendorsAction
{
    public function __construct(
        private readonly BroadcastWebsocketNotificationAction $broadcastAction,
        private readonly SendWhatsAppNotificationAction $whatsAppAction
    ) {}

    /**
     * Find and notify vendors who have products relevant to this RFQ.
     */
    public function execute(Rfq $rfq): void
    {
        $items = $rfq->items()
            ->with('catalogue')
            ->get()
            ->pluck('catalogue');

        $categories = $items->pluck('category')->filter()->map(fn ($value) => mb_strtolower(trim((string) $value)))->unique()->values()->all();
        $itemTokens = $items->flatMap(function ($catalogue) {
            if (! $catalogue) {
                return [];
            }

            return KeywordNormalizer::mergeMany([
                $catalogue->category ?? null,
                $catalogue->name ?? null,
                $catalogue->brand ?? null,
                $catalogue->specifications ?? null,
                $catalogue->keywords ?? [],
            ]);
        })->unique()->values()->all();

        $rfqTokens = KeywordNormalizer::mergeMany([
            $rfq->title,
            $rfq->description,
        ]);

        $searchTokens = collect(array_merge($categories, $itemTokens, $rfqTokens))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (empty($searchTokens)) {
            return;
        }

        $relevantVendors = Company::where('type', 'vendor')
            ->with(['catalogues', 'users'])
            ->get();

        $relevantVendors = $relevantVendors->filter(function (Company $vendor) use ($searchTokens) {
            $vendorTokens = collect()
                ->merge(KeywordNormalizer::mergeMany([
                    $vendor->keywords ?? [],
                    $vendor->industry_type ?? null,
                    $vendor->about ?? null,
                ]))
                ->merge($vendor->catalogues->flatMap(function ($catalogue) {
                    return KeywordNormalizer::mergeMany([
                        $catalogue->category ?? null,
                        $catalogue->name ?? null,
                        $catalogue->brand ?? null,
                        $catalogue->specifications ?? null,
                        $catalogue->keywords ?? [],
                    ]);
                }))
                ->filter()
                ->unique()
                ->values()
                ->all();

            return count(array_intersect($searchTokens, $vendorTokens)) > 0;
        });

        // Notify all users belonging to these relevant vendors
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

            $this->whatsAppAction->toCompany(
                $vendor,
                "Opportunity baru tersedia: {$rfq->title}. Produk Anda cocok dengan kebutuhan buyer. Silakan cek dan ikut serta.",
                false
            );
        }
    }
}
