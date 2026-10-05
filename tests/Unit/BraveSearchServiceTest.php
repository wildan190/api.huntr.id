<?php

namespace Tests\Unit;

use App\Domain\AI\Services\BraveSearchService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BraveSearchServiceTest extends TestCase
{
    public function test_market_price_search_continues_when_cache_is_unavailable(): void
    {
        config(['ai.brave_search_api_key' => 'test-key']);

        Cache::shouldReceive('get')
            ->once()
            ->andThrow(new \RuntimeException('cache unavailable'));
        Cache::shouldReceive('put')
            ->once()
            ->andThrow(new \RuntimeException('cache unavailable'));

        Http::fake([
            'api.search.brave.com/*' => Http::response([
                'web' => [
                    'results' => [
                        [
                            'title' => 'Mini PC Intel N100 harga Rp 2.500.000',
                            'url' => 'https://shop.example/mini-pc-1',
                            'description' => 'Mini PC Intel N100 baru',
                        ],
                        [
                            'title' => 'Mini PC Intel N100 harga Rp 2.700.000',
                            'url' => 'https://shop.example/mini-pc-2',
                            'description' => 'Mini PC Intel N100 baru',
                        ],
                        [
                            'title' => 'Mini PC Intel N100 harga Rp 2.800.000',
                            'url' => 'https://shop.example/mini-pc-3',
                            'description' => 'Mini PC Intel N100 baru',
                        ],
                    ],
                ],
            ]),
        ]);

        $service = new BraveSearchService;
        $result = $service->searchMarketPrice('Mini PC Intel N100');

        $this->assertSame(2_700_000.0, $result['avg_price']);
        $this->assertSame(3, $result['sample_count']);
        $this->assertSame('https://shop.example/mini-pc-2', $result['sources'][1]['link']);
        $this->assertFalse($service->lastSearchFailed());
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'api.search.brave.com'));
    }

    public function test_smartbulb_query_matches_split_words_in_web_results(): void
    {
        $service = new BraveSearchService;

        $this->assertTrue($service->isRelevantResult([
            'title' => 'Smart Bulb RGB 12W',
            'snippet' => 'Lampu pintar RGB 12 watt',
        ], 'smartbulb'));
    }

    public function test_market_price_search_retries_broadly_until_it_has_enough_price_sources(): void
    {
        config(['ai.brave_search_api_key' => 'test-key']);

        Cache::shouldReceive('get')->twice()->andReturn(null);
        Cache::shouldReceive('put')->twice()->andReturn(true);

        Http::fake([
            'api.search.brave.com/*' => Http::sequence()
                ->push([
                    'web' => [
                        'results' => [[
                            'title' => 'Smart Bulb RGB 12W Rp 120.000',
                            'url' => 'https://shop.example/bulb-1',
                            'description' => 'Smart bulb RGB 12 watt',
                        ]],
                    ],
                ])
                ->push([
                    'web' => [
                        'results' => [
                            [
                                'title' => 'Smart Bulb RGB 12W Rp 125.000',
                                'url' => 'https://shop.example/bulb-2',
                                'description' => 'Smart bulb RGB 12 watt',
                            ],
                            [
                                'title' => 'Smart Bulb RGB 12W Rp 130.000',
                                'url' => 'https://shop.example/bulb-3',
                                'description' => 'Smart bulb RGB 12 watt',
                            ],
                        ],
                    ],
                ]),
        ]);

        $result = (new BraveSearchService)->searchMarketPrice('smartbulb 12W');

        $this->assertSame(125_000.0, $result['avg_price']);
        $this->assertSame(3, $result['sample_count']);
        $this->assertCount(3, $result['raw_results']);
        $this->assertCount(3, $result['sources']);
        Http::assertSentCount(2);
    }
}
