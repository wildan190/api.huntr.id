<?php

namespace App\Domain\Catalogue\Http\Controllers;

use App\Domain\Catalogue\Models\Catalogue;
use App\Domain\Catalogue\Services\CatalogueCacheService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

class SeoController extends \App\Http\Controllers\Controller
{
    /**
     * Get SEO metadata and Schema Markup for a catalogue item.
     */
    public function show(Catalogue $catalogue, CatalogueCacheService $cacheService): JsonResponse
    {
        // Try to get from cache first
        $cachedData = $cacheService->getSeoData($catalogue);
        
        if ($cachedData) {
            return response()->json($cachedData, 200);
        }
        
        $catalogue->load('company');
        
        $frontendUrl = rtrim(config('app.frontend_url', env('FRONTEND_URL', 'https://app.huntr.id')), '/');
        $canonicalUrl = "{$frontendUrl}/catalogues/{$catalogue->id}";
        
        // Construct standard SEO values
        $brand = $catalogue->brand ?? 'Generic';
        $companyName = $catalogue->company ? $catalogue->company->name : 'Huntr Seller';
        
        $title = "Buy {$catalogue->name} - {$brand} | Huntr";
        
        $descParts = [];
        $descParts[] = "Buy {$catalogue->name} by {$companyName} on Huntr.";
        if ($catalogue->category) {
            $descParts[] = "Category: {$catalogue->category}.";
        }
        if ($catalogue->brand) {
            $descParts[] = "Brand: {$catalogue->brand}.";
        }
        if ($catalogue->specifications) {
            $descParts[] = "Specifications: " . mb_strimwidth($catalogue->specifications, 0, 100, "...");
        }
        $metaDescription = implode(' ', $descParts);

        // Keywords
        $keywords = is_array($catalogue->keywords) ? $catalogue->keywords : [];
        if ($catalogue->category) $keywords[] = $catalogue->category;
        if ($catalogue->brand) $keywords[] = $catalogue->brand;
        $keywords[] = $catalogue->name;
        $keywords[] = 'B2B procurement';
        $keywords[] = 'marketplace';
        $metaKeywords = implode(', ', array_unique(array_filter($keywords)));

        // Image URL
        $imageUrl = null;
        if ($catalogue->image_path) {
            $imageUrl = $this->getImageUrl($catalogue->image_path);
        }

        // Generate JSON-LD Schema.org Markup
        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => $catalogue->name,
            'image' => $imageUrl,
            'description' => $catalogue->specifications ?? "{$catalogue->name} - B2B Catalogue item on Huntr.",
            'sku' => $catalogue->item_code ?? $catalogue->id,
            'mpn' => $catalogue->item_code ?? $catalogue->id,
            'brand' => [
                '@type' => 'Brand',
                'name' => $brand,
            ],
            'offers' => [
                '@type' => 'Offer',
                'url' => $canonicalUrl,
                'priceCurrency' => 'IDR',
                'price' => '0',
                'priceSpecification' => [
                    '@type' => 'PriceSpecification',
                    'price' => '0',
                    'priceCurrency' => 'IDR',
                    'priceType' => 'https://schema.org/NegotiatedPrice',
                ],
                'availability' => 'https://schema.org/InStock',
                'seller' => [
                    '@type' => 'Organization',
                    'name' => $companyName,
                ]
            ]
        ];

        $responseData = [
            'data' => [
                'id' => $catalogue->id,
                'title' => $title,
                'meta_description' => $metaDescription,
                'meta_keywords' => $metaKeywords,
                'canonical_url' => $canonicalUrl,
                'image_url' => $imageUrl,
                'schema_markup' => $schema,
            ]
        ];
        
        // Store in cache for future requests
        $cacheService->storeSeoData($catalogue, $responseData);
        
        return response()->json($responseData);
    }

    private function getImageUrl(string $path): string
    {
        if (filter_var($path, FILTER_VALIDATE_URL)) {
            return $path;
        }

        return Storage::url($path);
    }
}
