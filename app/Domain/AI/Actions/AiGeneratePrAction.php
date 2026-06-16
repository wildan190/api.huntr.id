<?php

namespace App\Domain\AI\Actions;

use App\Domain\AI\Services\GenkitService;
use App\Domain\Catalogue\Models\Catalogue;

/**
 * AiGeneratePrAction
 *
 * Membuat draft Purchase Requisition (PR/RFQ) secara otomatis
 * berdasarkan natural language prompt user dan produk yang cocok.
 */
class AiGeneratePrAction
{
    public function __construct(
        private readonly GenkitService $genkit
    ) {}

    /**
     * @param string $userPrompt Natural language prompt user
     * @param array $catalogueIds Array UUID produk yang dipilih
     * @return array Draft PR siap digunakan
     */
    public function execute(string $userPrompt, array $catalogueIds = []): array
    {
        // Fetch catalogue data yang dipilih
        $query = Catalogue::with('company');

        if (!empty($catalogueIds)) {
            $query->whereIn('id', $catalogueIds);
        }

        $matchedItems = $query->limit(20)->get()->map(fn($c) => [
            'id'             => $c->id,
            'name'           => $c->name,
            'item_code'      => $c->item_code,
            'category'       => $c->category,
            'brand'          => $c->brand,
            'specifications' => $c->specifications,
            'uom'            => $c->uom,
            'vendor'         => $c->company?->name,
        ])->toArray();

        if (empty($matchedItems)) {
            // Fallback: cari berdasarkan prompt saja
            return [
                'title'           => 'PR - ' . substr($userPrompt, 0, 60),
                'description'     => $userPrompt,
                'suggested_items' => [],
                'duration_days'   => 7,
                'priority'        => 'Normal',
                'notes'           => 'Item tidak ditemukan di katalog. Silakan tambahkan manual.',
                'source'          => 'fallback',
            ];
        }

        $draft = $this->genkit->generatePrDraft($userPrompt, $matchedItems);

        // Validasi dan enrich suggested items dengan data asli
        $enrichedItems = [];
        foreach ($draft['suggested_items'] ?? [] as $suggested) {
            $catalogueId = $suggested['catalogue_id'] ?? null;
            if (!$catalogueId) continue;

            $catalogue = collect($matchedItems)->firstWhere('id', $catalogueId);
            if ($catalogue) {
                $enrichedItems[] = array_merge($suggested, [
                    'catalogue'     => $catalogue,
                    'catalogue_id'  => $catalogueId,
                    'qty'           => max(1, (int) ($suggested['qty'] ?? 1)),
                    'estimated_price' => (float) ($suggested['estimated_price'] ?? 0),
                ]);
            }
        }

        return array_merge($draft, [
            'suggested_items' => $enrichedItems,
            'source'          => 'ai',
        ]);
    }
}
