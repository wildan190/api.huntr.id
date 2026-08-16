<?php

namespace App\Domain\AI\Actions;

use App\Domain\AI\Services\OpenAiService;
use App\Domain\Catalogue\Models\Catalogue;

/**
 * AiCompareProductsAction
 *
 * Membandingkan beberapa produk katalog menggunakan AI OpenAI.
 */
class AiCompareProductsAction
{
    public function __construct(
        private readonly OpenAiService $openAi
    ) {}

    /**
     * @param array $catalogueIds Array of catalogue UUIDs
     * @return array Hasil perbandingan AI
     */
    public function execute(array $catalogueIds): array
    {
        if (count($catalogueIds) < 2) {
            throw new \InvalidArgumentException('Minimal 2 produk diperlukan untuk perbandingan.');
        }

        if (count($catalogueIds) > 5) {
            throw new \InvalidArgumentException('Maksimal 5 produk dapat dibandingkan sekaligus.');
        }

        // Fetch catalogue data
        $catalogues = Catalogue::with('company')
            ->whereIn('id', $catalogueIds)
            ->get()
            ->map(fn($c) => [
                'id'             => $c->id,
                'name'           => $c->name,
                'item_code'      => $c->item_code,
                'category'       => $c->category,
                'brand'          => $c->brand,
                'specifications' => $c->specifications,
                'uom'            => $c->uom,
                'vendor'         => $c->company?->name,
                'vendor_type'    => $c->company?->type,
            ])
            ->toArray();

        if (empty($catalogues)) {
            throw new \RuntimeException('Produk tidak ditemukan.');
        }

        $aiResult = $this->openAi->compareProducts($catalogues);

        return [
            'catalogues'  => $catalogues,
            'ai_analysis' => $aiResult,
        ];
    }
}
