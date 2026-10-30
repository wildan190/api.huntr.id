<?php

namespace App\Domain\AI\Actions;

use App\Domain\AI\Models\AiUsageLog;
use App\Domain\AI\Services\AiQuotaService;
use App\Domain\AI\Services\HuntrAssistToolGateway;
use App\Domain\AI\Services\OpenAiService;
use App\Domain\AI\Services\PerplexityAgentException;
use App\Domain\AI\Services\PerplexityAgentService;
use App\Domain\Company\Models\Company;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AskHuntrAssistAction
{
    public function __construct(
        private readonly PerplexityAgentService $agent,
        private readonly AiQuotaService $quota,
        private readonly OpenAiService $openAi,
        private readonly HuntrAssistToolGateway $platformTools,
    ) {}

    public function execute(string $query, ?Company $company, ?string $userId, ?string $previousResponseId = null): array
    {
        if ($company && $this->platformTools->supports($query)) {
            return $this->answerWithPlatformTools($query, $company);
        }

        $companyId = $company?->id;
        if ($companyId !== null) {
            $this->quota->assertAvailable($companyId);
        }

        try {
            $result = $this->agent->ask($query, $previousResponseId);
        } catch (PerplexityAgentException $exception) {
            if (! $this->shouldFallbackToOpenAi($exception)) {
                throw $exception;
            }

            return $this->askWithOpenAiFallback($query, $companyId, $exception);
        }

        if ($companyId !== null) {
            $usage = $result['usage'];
            AiUsageLog::create([
                'company_id' => $companyId,
                'user_id' => $userId,
                'endpoint' => 'huntr-assist',
                'model' => $result['model'] ?? 'perplexity-agent',
                'prompt_tokens' => $usage['input_tokens'],
                'completion_tokens' => $usage['output_tokens'],
                'total_tokens' => $usage['total_tokens'],
                'estimated_cost_usd' => $usage['estimated_cost_usd'],
            ]);
        }

        return array_merge($result, [
            'provider' => 'perplexity',
            'fallback' => false,
        ]);
    }

    private function shouldFallbackToOpenAi(PerplexityAgentException $exception): bool
    {
        if (! config('ai.huntr_assist.openai_fallback', true)) {
            return false;
        }

        return in_array($exception->errorCode, [
            'perplexity_not_configured',
            'perplexity_paid_credits_required',
            'perplexity_rate_limited',
            'perplexity_unavailable',
        ], true);
    }

    private function askWithOpenAiFallback(string $query, ?string $companyId, PerplexityAgentException $exception): array
    {
        try {
            $answer = $this->openAi->ask(
                $query,
                implode(' ', [
                    'You are Huntr Assist for an Indonesian enterprise procurement platform.',
                    'Reply in clear Indonesian unless the user writes in another language.',
                    'You are running as a fallback without live web access, so say when current facts cannot be verified.',
                    'Do not invent Huntr features, procurement data, prices, vendors, or sources.',
                ]),
                $companyId,
                'huntr-assist-openai-fallback',
                0.2,
            );
        } catch (\Throwable $fallbackException) {
            Log::warning('Huntr Assist OpenAI fallback failed.', [
                'perplexity_error' => $exception->errorCode,
                'exception' => $fallbackException::class,
            ]);

            throw new PerplexityAgentException(
                'Huntr Assist tidak tersedia karena Perplexity dan OpenAI belum dapat memproses permintaan.',
                503,
                'huntr_assist_all_providers_unavailable',
                previous: $fallbackException,
            );
        }

        return [
            'response_id' => null,
            'answer' => $answer,
            'sources' => [],
            'model' => (string) config('ai.openai_model', 'gpt-4o-mini'),
            'usage' => null,
            'provider' => 'openai',
            'fallback' => true,
            'fallback_reason' => $exception->errorCode,
        ];
    }

    private function answerWithPlatformTools(string $query, Company $company): array
    {
        try {
            $answer = $this->openAi->answerWithHuntrTools($query, $company, $this->platformTools);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            Log::warning('Huntr Assist platform tools failed.', ['exception' => $exception::class]);
            throw new PerplexityAgentException('Huntr Assist belum dapat membaca data platform saat ini.', 503, 'huntr_platform_tools_unavailable', previous: $exception);
        }

        return [
            'response_id' => null,
            'answer' => $answer,
            'sources' => [],
            'model' => (string) config('ai.openai_model', 'gpt-4o-mini'),
            'usage' => null,
            'provider' => 'huntr_platform',
            'fallback' => false,
        ];
    }
}
