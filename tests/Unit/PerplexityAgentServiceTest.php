<?php

namespace Tests\Unit;

use App\Domain\AI\Services\PerplexityAgentException;
use App\Domain\AI\Services\PerplexityAgentService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PerplexityAgentServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai.perplexity.api_key' => 'test-perplexity-key',
            'ai.perplexity.endpoint' => 'https://api.perplexity.ai/v1/agent',
            'ai.perplexity.effort' => 'low',
            'ai.perplexity.timeout' => 5,
            'ai.perplexity.max_output_tokens' => 300,
        ]);
    }

    public function test_it_returns_answer_and_deduplicated_citations(): void
    {
        Http::fake([
            'api.perplexity.ai/v1/agent' => Http::response([
                'id' => 'resp_123',
                'status' => 'completed',
                'model' => 'openai/gpt-5.6-sol',
                'output' => [
                    ['type' => 'web_search_call', 'results' => [
                        ['title' => 'Dokumen resmi', 'url' => 'https://example.test/source'],
                    ]],
                    ['type' => 'message', 'content' => [
                        ['type' => 'output_text', 'text' => 'Jawaban terverifikasi.', 'annotations' => [
                            ['title' => 'Dokumen resmi', 'url' => 'https://example.test/source'],
                            ['title' => 'Referensi kedua', 'url' => 'https://example.test/other'],
                        ]],
                    ]],
                ],
                'usage' => [
                    'input_tokens' => 10,
                    'output_tokens' => 20,
                    'total_tokens' => 30,
                    'cost' => ['total_cost' => 0.01],
                ],
            ]),
        ]);

        $result = (new PerplexityAgentService)->ask('Apa status informasi ini?');

        $this->assertSame('resp_123', $result['response_id']);
        $this->assertSame('Jawaban terverifikasi.', $result['answer']);
        $this->assertSame(30, $result['usage']['total_tokens']);
        $this->assertCount(2, $result['sources']);
        Http::assertSent(function (Request $request): bool {
            return $request->hasHeader('Authorization', 'Bearer test-perplexity-key')
                && $request['effort'] === 'low'
                && $request['tools'] === [['type' => 'web_search']];
        });
    }

    public function test_it_surfaces_rate_limits_with_retry_information(): void
    {
        Http::fake([
            'api.perplexity.ai/v1/agent' => Http::response([], 429, ['Retry-After' => '15']),
        ]);

        try {
            (new PerplexityAgentService)->ask('Cek berita terbaru');
            $this->fail('Expected PerplexityAgentException was not thrown.');
        } catch (PerplexityAgentException $exception) {
            $this->assertSame(429, $exception->status);
            $this->assertSame('perplexity_rate_limited', $exception->errorCode);
            $this->assertSame('15', $exception->retryAfter);
        }
    }

    public function test_it_does_not_disguise_provider_access_denials_as_gateway_errors(): void
    {
        Http::fake([
            'api.perplexity.ai/v1/agent' => Http::response([
                'error' => ['code' => 'forbidden', 'message' => 'The Agent API requires paid credits.'],
            ], 403),
        ]);

        try {
            (new PerplexityAgentService)->ask('Cari update harga pasar');
            $this->fail('Expected PerplexityAgentException was not thrown.');
        } catch (PerplexityAgentException $exception) {
            $this->assertSame(403, $exception->status);
            $this->assertSame('perplexity_paid_credits_required', $exception->errorCode);
        }
    }
}
