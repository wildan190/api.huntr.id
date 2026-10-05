<?php

namespace Tests\Unit;

use App\Domain\AI\Services\OpenAiService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenAiServiceTest extends TestCase
{
    public function test_intent_discards_ungrounded_fields_and_uses_explicit_quantity(): void
    {
        config(['ai.openai_api_key' => 'test-key']);

        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'keywords' => ['Mini PC Intel N100', 'workstation'],
                            'target_items' => [[
                                'name' => 'Mini PC Intel N100',
                                'brand' => 'HP',
                                'spec_requirements' => '32GB RAM',
                                'quantity' => 99,
                                'uom' => 'unit',
                                'budget_hint_idr' => 2_500_000,
                            ]],
                        ]),
                    ],
                ]],
                'usage' => [],
            ]),
        ]);

        $intent = (new OpenAiService)->extractSearchIntent(
            'Butuh 2 unit Mini PC Intel N100 untuk hpv testing dengan anggaran total Rp 10.000.000'
        );

        $this->assertSame(['Mini PC Intel N100'], $intent['keywords']);
        $this->assertSame('Mini PC Intel N100', $intent['target_items'][0]['name']);
        $this->assertNull($intent['target_items'][0]['brand']);
        $this->assertNull($intent['target_items'][0]['spec_requirements']);
        $this->assertSame(2, $intent['target_items'][0]['quantity']);
        $this->assertNull($intent['target_items'][0]['budget_hint_idr']);
    }

    public function test_pr_description_preserves_buyer_text_without_generating_claims(): void
    {
        Http::fake();

        $draft = (new OpenAiService)->generatePrDraft('Butuh 2 unit Mini PC Intel N100', [
            ['name' => 'Mini PC Intel N100', 'qty' => 2],
        ]);

        $this->assertSame('Permintaan pengadaan: Butuh 2 unit Mini PC Intel N100', $draft['description']);
        $this->assertNull($draft['business_justification']);
        $this->assertNull($draft['manager_notes']);
        Http::assertNothingSent();
    }
}
