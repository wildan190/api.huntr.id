<?php

namespace Tests\Unit;

use App\Domain\News\Services\NewsService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class NewsServiceTest extends TestCase
{
    #[Test]
    public function it_combines_only_economy_and_politics_news(): void
    {
        config([
            'services.newsapi.key' => 'test-key',
            'services.newsapi.endpoint' => 'https://newsapi.example/v2',
        ]);
        Cache::forget('newsapi:huntr:v2:economy:politics');
        Http::fake(function (Request $request) {
            $politics = str_contains(urldecode($request->url()), 'politics');

            return Http::response([
                'articles' => [[
                    'title' => $politics ? 'Kebijakan baru' : 'Ekonomi Indonesia',
                    'description' => $politics ? 'Ringkasan politik' : 'Ringkasan ekonomi',
                    'url' => $politics ? 'https://example.test/politics' : 'https://example.test/economy',
                    'urlToImage' => null,
                    'source' => ['name' => $politics ? 'Politik News' : 'Ekonomi News'],
                    'publishedAt' => $politics ? '2026-10-30T09:00:00Z' : '2026-10-30T08:00:00Z',
                ]],
            ]);
        });

        $result = app(NewsService::class)->headlines();

        $this->assertCount(2, $result['articles']);
        $this->assertSame(['politics', 'economy'], array_column($result['articles'], 'category'));
        Http::assertSentCount(2);
    }
}
