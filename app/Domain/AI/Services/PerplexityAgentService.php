<?php

namespace App\Domain\AI\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Server-side gateway for Perplexity Agent API.
 *
 * Keeping this integration in Laravel prevents the provider key from being
 * included in the web client. The public method returns only the safe response
 * contract needed by Huntr Assist: answer text, cited sources, and usage.
 */
class PerplexityAgentService
{
    public function ask(string $input, ?string $previousResponseId = null): array
    {
        $apiKey = (string) config('ai.perplexity.api_key');

        if ($apiKey === '') {
            throw new PerplexityAgentException(
                'Huntr Assist belum dikonfigurasi. Tambahkan PERPLEXITY_API_KEY pada environment server.',
                503,
                'perplexity_not_configured',
            );
        }

        $payload = array_filter([
            'effort' => (string) config('ai.perplexity.effort', 'low'),
            'input' => $input,
            'previous_response_id' => $previousResponseId,
            'max_output_tokens' => (int) config('ai.perplexity.max_output_tokens', 700),
            'tools' => [['type' => 'web_search']],
            'instructions' => implode(' ', [
                'You are Huntr Assist, the in-product assistant for an Indonesian enterprise procurement platform.',
                'Answer in clear Indonesian unless the user writes in another language.',
                'Use web search for factual or current questions and be concise.',
                'When a question is about using Huntr, give practical steps but do not invent product capabilities.',
                'Do not request secrets, credentials, or personal payment data.',
            ]),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        try {
            $response = Http::acceptJson()
                ->withToken($apiKey)
                ->timeout((int) config('ai.perplexity.timeout', 30))
                ->post((string) config('ai.perplexity.endpoint'), $payload);
        } catch (\Throwable $exception) {
            Log::warning('Huntr Assist provider request failed.', [
                'exception' => $exception::class,
            ]);

            throw new PerplexityAgentException(
                'Huntr Assist sedang tidak dapat dihubungi. Coba lagi sesaat lagi.',
                503,
                'perplexity_unavailable',
                previous: $exception,
            );
        }

        if ($response->status() === 429) {
            throw new PerplexityAgentException(
                'Huntr Assist sedang mencapai batas permintaan. Coba lagi sesuai waktu yang dianjurkan.',
                429,
                'perplexity_rate_limited',
                retryAfter: $response->header('Retry-After'),
            );
        }

        if (in_array($response->status(), [401, 403], true)) {
            $providerError = $this->providerError($response->json());
            Log::warning('Huntr Assist provider denied access.', [
                'status' => $response->status(),
                'provider_code' => $providerError['code'],
                'request_id' => $response->header('X-Request-Id'),
            ]);

            $requiresPaidCredits = str_contains(
                strtolower((string) $providerError['message']),
                'requires paid credits',
            );

            throw new PerplexityAgentException(
                $requiresPaidCredits
                    ? 'Perplexity Agent API memerlukan paid credits. Tambahkan saldo berbayar pada organisasi Perplexity sebelum menggunakan Huntr Assist.'
                    : 'Perplexity menolak akses Agent API. Periksa API key, project, dan billing/akses Agent API di Perplexity Console.',
                403,
                $requiresPaidCredits ? 'perplexity_paid_credits_required' : 'perplexity_access_denied',
            );
        }

        if (! $response->successful()) {
            $providerError = $this->providerError($response->json());
            Log::warning('Huntr Assist provider returned an error.', [
                'status' => $response->status(),
                'provider_code' => $providerError['code'],
                'request_id' => $response->header('X-Request-Id'),
            ]);

            throw new PerplexityAgentException(
                'Huntr Assist belum dapat menjawab saat ini. Coba lagi nanti.',
                502,
                'perplexity_provider_error',
            );
        }

        $responseData = $response->json();
        if (! is_array($responseData)) {
            throw new PerplexityAgentException(
                'Huntr Assist menerima respons yang tidak dapat diproses. Coba lagi nanti.',
                502,
                'perplexity_invalid_response',
            );
        }
        $answer = $this->extractOutputText($responseData);

        if ($answer === '') {
            throw new PerplexityAgentException(
                'Huntr Assist belum menghasilkan jawaban. Silakan coba pertanyaan lain.',
                502,
                'perplexity_empty_response',
            );
        }

        return [
            'response_id' => $responseData['id'] ?? null,
            'answer' => $answer,
            'sources' => $this->extractSources($responseData),
            'model' => $responseData['model'] ?? null,
            'usage' => [
                'input_tokens' => (int) data_get($responseData, 'usage.input_tokens', 0),
                'output_tokens' => (int) data_get($responseData, 'usage.output_tokens', 0),
                'total_tokens' => (int) data_get($responseData, 'usage.total_tokens', 0),
                'estimated_cost_usd' => (float) data_get($responseData, 'usage.cost.total_cost', 0),
            ],
        ];
    }

    /** @param array<string, mixed> $response */
    private function extractOutputText(array $response): string
    {
        $text = [];

        foreach ((array) ($response['output'] ?? []) as $item) {
            if (($item['type'] ?? null) !== 'message') {
                continue;
            }

            foreach ((array) ($item['content'] ?? []) as $content) {
                if (($content['type'] ?? null) === 'output_text' && is_string($content['text'] ?? null)) {
                    $text[] = $content['text'];
                }
            }
        }

        return trim(implode("\n", $text));
    }

    /** @param array<string, mixed> $response
     * @return list<array{title: string, url: string}>
     */
    private function extractSources(array $response): array
    {
        $sources = [];
        $add = static function (array $source) use (&$sources): void {
            $url = filter_var($source['url'] ?? null, FILTER_VALIDATE_URL);
            if (! $url || isset($sources[$url])) {
                return;
            }

            $sources[$url] = [
                'title' => trim((string) ($source['title'] ?? parse_url($url, PHP_URL_HOST) ?? 'Sumber web')),
                'url' => $url,
            ];
        };

        foreach ((array) ($response['search_results'] ?? []) as $result) {
            if (is_array($result)) {
                $add($result);
            }
        }

        foreach ((array) ($response['output'] ?? []) as $item) {
            foreach ((array) ($item['results'] ?? []) as $result) {
                if (is_array($result)) {
                    $add($result);
                }
            }

            foreach ((array) ($item['content'] ?? []) as $content) {
                foreach ((array) ($content['annotations'] ?? []) as $annotation) {
                    if (is_array($annotation)) {
                        $add($annotation);
                    }
                }
            }
        }

        return array_slice(array_values($sources), 0, 8);
    }

    /**
     * Keep provider diagnostics useful without logging raw requests, prompts, or secrets.
     *
     * @return array{code: string|null, message: string|null}
     */
    private function providerError(mixed $payload): array
    {
        if (! is_array($payload)) {
            return ['code' => null, 'message' => null];
        }

        $code = data_get($payload, 'error.code') ?? data_get($payload, 'code');
        $message = data_get($payload, 'error.message') ?? data_get($payload, 'message');

        return [
            'code' => is_scalar($code) ? (string) $code : null,
            'message' => is_scalar($message) ? (string) $message : null,
        ];
    }
}
