<?php

namespace Tests\Unit;

use App\Domain\AI\Services\AgenticProcurementService;
use App\Domain\AI\Services\BraveSearchService;
use App\Domain\AI\Services\OpenAiService;
use App\Domain\Rfq\Actions\CreateRfqAction;
use Tests\TestCase;

class AgenticProcurementServiceTest extends TestCase
{
    public function test_web_listings_are_returned_when_there_are_not_two_verified_candidates(): void
    {
        $service = new AgenticProcurementService(
            $this->createMock(OpenAiService::class),
            $this->createMock(BraveSearchService::class),
            $this->createMock(CreateRfqAction::class),
        );
        $method = new \ReflectionMethod($service, 'runComparison');

        [$comparison, $step] = $method->invoke($service, 'smartbulb', [], [], [
            'smartbulb' => [
                'item_name' => 'smartbulb',
                'results' => [[
                    'title' => 'Smart Bulb RGB 12W',
                    'link' => 'https://shop.example/smart-bulb',
                    'snippet' => 'Lampu pintar RGB 12 watt',
                    'source' => 'shop.example',
                    'price' => 125000,
                ]],
            ],
        ]);

        $this->assertSame('brave_web_listings', $comparison['source']);
        $this->assertTrue($comparison['unverified']);
        $this->assertNull($comparison['winner_id']);
        $this->assertNull($comparison['comparison_matrix'][0]['score']);
        $this->assertSame(125000.0, $comparison['comparison_matrix'][0]['web_listing_price']);
        $this->assertSame('https://shop.example/smart-bulb', $comparison['comparison_matrix'][0]['web_sources'][0]['link']);
        $this->assertSame('brave_web_listings', $step['source']);
    }
}
