<?php

namespace Tests\Unit;

use App\Domain\AI\Services\GenkitService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GenkitServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'ai.genkit_api_key' => 'test-api-key',
            'ai.endpoint' => 'https://generativelanguage.googleapis.com/v1beta',
            'ai.model' => 'gemini-2.0-flash',
            'ai.timeout' => 5,
            'ai.max_tokens' => 100,
        ]);
    }

    public function test_ask_sends_correct_http_request_and_returns_text(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                ['text' => 'Hello from Gemini!']
                            ]
                        ]
                    ]
                ]
            ], 200)
        ]);

        $service = new GenkitService();
        $response = $service->ask('Hello', 'Be helpful');

        $this->assertEquals('Hello from Gemini!', $response);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'key=test-api-key') &&
                   $request['contents'][0]['parts'][0]['text'] === 'Hello' &&
                   $request['systemInstruction']['parts'][0]['text'] === 'Be helpful';
        });
    }

    public function test_ask_json_parses_json_and_cleans_markdown_blocks(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                ['text' => "```json\n{\n  \"key\": \"value\"\n}\n```"]
                            ]
                        ]
                    ]
                ]
            ], 200)
        ]);

        $service = new GenkitService();
        $response = $service->askJson('Return JSON');

        $this->assertEquals(['key' => 'value'], $response);
    }

    public function test_ask_json_falls_back_when_malformed_json_returned(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                ['text' => "Here is some extra text before JSON {\"key\": \"value\"} and after."]
                            ]
                        ]
                    ]
                ]
            ], 200)
        ]);

        $service = new GenkitService();
        $response = $service->askJson('Return JSON');

        $this->assertEquals(['key' => 'value'], $response);
    }
}
