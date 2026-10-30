<?php

namespace App\Domain\News\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class NewsService
{
    private const ALLOWED_CATEGORIES = ['economy', 'politics'];

    /** @return array{articles: array<int, array<string, mixed>>, updated_at: string, notice: string|null} */
    public function headlines(string $category = 'all'): array
    {
        $category = strtolower($category);
        $categories = $category === 'all' ? self::ALLOWED_CATEGORIES : [$category];

        if (array_diff($categories, self::ALLOWED_CATEGORIES) !== []) {
            abort(422, 'Kategori berita tidak tersedia.');
        }

        $key = config('services.newsapi.key');
        if (! is_string($key) || $key === '') {
            return [
                'articles' => [],
                'updated_at' => now()->toIso8601String(),
                'notice' => 'NEWSAPI_KEY belum dikonfigurasi pada server.',
            ];
        }

        return Cache::remember(
            'newsapi:huntr:v2:'.implode(':', $categories),
            now()->addMinutes(10),
            function () use ($categories, $key): array {
                $feeds = collect($categories)
                    ->map(fn (string $item) => $this->fetchCategory($item, $key));
                $articles = $feeds
                    ->flatMap(fn (array $feed) => $feed['articles'])
                    ->unique('url')
                    ->sortByDesc('published_at')
                    ->take(20)
                    ->values()
                    ->all();

                return [
                    'articles' => $articles,
                    'updated_at' => now()->toIso8601String(),
                    'notice' => $articles === [] ? $feeds->pluck('notice')->filter()->first() : null,
                ];
            },
        );
    }

    /** @return array{articles: array<int, array<string, mixed>>, notice: string|null} */
    private function fetchCategory(string $category, string $key): array
    {
        $baseUrl = rtrim((string) config('services.newsapi.endpoint'), '/');
        $query = $category === 'economy'
            ? 'economy OR business OR markets OR bitcoin'
            : 'politics OR government OR election';

        $response = Http::withHeaders(['X-Api-Key' => $key])
            ->acceptJson()
            ->timeout((int) config('services.newsapi.timeout', 10))
            ->get("{$baseUrl}/everything", [
                'q' => $query,
                'language' => 'en',
                'sortBy' => 'publishedAt',
                'pageSize' => 12,
            ]);

        if ($response->failed()) {
            return [
                'articles' => [],
                'notice' => (string) ($response->json('message') ?: 'NewsAPI belum dapat mengembalikan berita.'),
            ];
        }

        return [
            'articles' => collect($response->json('articles', []))
                ->filter(fn (mixed $article) => is_array($article) && filled($article['title'] ?? null) && filled($article['url'] ?? null))
                ->map(fn (array $article) => [
                    'category' => $category,
                    'title' => (string) $article['title'],
                    'summary' => (string) ($article['description'] ?? ''),
                    'url' => (string) $article['url'],
                    'image_url' => $article['urlToImage'] ?? null,
                    'source' => (string) data_get($article, 'source.name', 'News'),
                    'published_at' => $article['publishedAt'] ?? null,
                ])
                ->all(),
            'notice' => null,
        ];
    }
}
