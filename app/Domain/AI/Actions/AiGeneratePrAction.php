<?php

namespace App\Domain\AI\Actions;

use App\Domain\AI\Services\OpenAiService;
use App\Domain\Catalogue\Models\Catalogue;

/**
 * AiGeneratePrAction
 *
 * Membuat draft Purchase Requisition (PR/RFQ) secara otomatis
 * berdasarkan natural language prompt user dan produk yang cocok menggunakan OpenAI.
 */
class AiGeneratePrAction
{
    public function __construct(
        private readonly OpenAiService $openAi
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

        $draft = $this->openAi->generatePrDraft($userPrompt, $matchedItems);

        // Validasi dan enrich suggested items dengan data asli
        $enrichedItems = [];
        foreach ($draft['suggested_items'] ?? [] as $suggested) {
            $catalogueId = $suggested['catalogue_id'] ?? null;
            $catalogue = $catalogueId ? collect($matchedItems)->firstWhere('id', $catalogueId) : null;

            $enrichedItems[] = array_merge($suggested, [
                'catalogue'       => $catalogue,
                'catalogue_id'    => $catalogueId,
                'name'            => $suggested['name'] ?? ($catalogue['name'] ?? 'Item Pengadaan'),
                'qty'             => max(1, (int) ($suggested['qty'] ?? 1)),
                'estimated_price' => (float) ($suggested['estimated_price'] ?? 0),
            ]);
        }

        return array_merge($draft, [
            'suggested_items' => $enrichedItems,
            'source'          => 'ai_openai',
        ]);
    }
}
