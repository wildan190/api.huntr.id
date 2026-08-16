<?php

namespace App\Domain\AI\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * OpenAiService
 *
 * Wrapper untuk OpenAI ChatGPT API.
 * Bertanggung jawab untuk komunikasi dengan OpenAI ChatGPT API (gpt-4o-mini / gpt-4o).
 */
class OpenAiService
{
    private string $apiKey;
    private string $model;
    private int $timeout;

    public function __construct()
    {
        $rawKey = config('ai.openai_api_key') ?: env('OPENAI_API_KEY');
        $this->apiKey = is_string($rawKey) ? $rawKey : '';
        $rawModel = config('ai.openai_model') ?: env('OPENAI_MODEL');
        $this->model  = is_string($rawModel) ? $rawModel : 'gpt-4o-mini';
        $this->timeout = (int) config('ai.timeout', 30);
    }

    /**
     * Kirim prompt ke OpenAI dan dapatkan teks response.
     */
    public function ask(string $prompt, string $systemInstruction = ''): string
    {
        if (empty($this->apiKey)) {
            Log::warning('OpenAiService: OpenAI API key is missing.');
            throw new \RuntimeException('OpenAI API Key belum terkonfigurasi.');
        }

        $messages = [];
        if (!empty($systemInstruction)) {
            $messages[] = ['role' => 'system', 'content' => $systemInstruction];
        }
        $messages[] = ['role' => 'user', 'content' => $prompt];

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type'  => 'application/json',
            ])
            ->timeout($this->timeout)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model'       => $this->model,
                'messages'    => $messages,
                'temperature' => 0.7,
            ]);

            if ($response->failed()) {
                Log::error('OpenAiService API error', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
                throw new \RuntimeException('OpenAI API Error: ' . $response->status() . ' - ' . $response->body());
            }

            $data = $response->json();
            return $data['choices'][0]['message']['content'] ?? '';

        } catch (\Exception $e) {
            Log::error('OpenAiService Exception', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Kirim prompt dan minta JSON response dari OpenAI.
     */
    public function askJson(string $prompt, string $systemInstruction = ''): array
    {
        $jsonPrompt = $prompt . "\n\nPenting: Balas HANYA dengan JSON valid, tanpa markdown format `json`, tanpa penjelasan ekstra.";
        $rawResponse = $this->ask($jsonPrompt, $systemInstruction);

        $cleaned = preg_replace('/^```(?:json)?\s*/m', '', $rawResponse);
        $cleaned = preg_replace('/\s*```$/m', '', $cleaned);
        $cleaned = trim($cleaned);

        $decoded = json_decode($cleaned, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            preg_match('/[\{\[].*[\}\]]/s', $cleaned, $matches);
            if (!empty($matches[0])) {
                $decoded = json_decode($matches[0], true);
            }
        }

        return is_array($decoded) ? $decoded : [];
    }
}
