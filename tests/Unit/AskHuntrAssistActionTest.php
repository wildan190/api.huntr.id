<?php

namespace Tests\Unit;

use App\Domain\AI\Actions\AskHuntrAssistAction;
use App\Domain\AI\Services\AiQuotaService;
use App\Domain\AI\Services\HuntrAssistToolGateway;
use App\Domain\AI\Services\OpenAiService;
use App\Domain\AI\Services\PerplexityAgentException;
use App\Domain\AI\Services\PerplexityAgentService;
use App\Domain\Company\Models\Company;
use Mockery;
use Tests\TestCase;

class AskHuntrAssistActionTest extends TestCase
{
    public function test_it_answers_platform_questions_through_the_scoped_tool_agent(): void
    {
        $company = Mockery::mock(Company::class);

        $platformTools = Mockery::mock(HuntrAssistToolGateway::class);
        $platformTools->shouldReceive('supports')->once()->with('Tampilkan PO terakhir')->andReturnTrue();

        $openAi = Mockery::mock(OpenAiService::class);
        $openAi->shouldReceive('answerWithHuntrTools')
            ->once()
            ->with('Tampilkan PO terakhir', $company, $platformTools)
            ->andReturn('PO terakhir untuk workspace ini adalah PO-2026-0001.');

        $action = new AskHuntrAssistAction(
            Mockery::mock(PerplexityAgentService::class),
            Mockery::mock(AiQuotaService::class),
            $openAi,
            $platformTools,
        );

        $result = $action->execute('Tampilkan PO terakhir', $company, 'user-id');

        $this->assertSame('huntr_platform', $result['provider']);
        $this->assertFalse($result['fallback']);
        $this->assertSame('PO terakhir untuk workspace ini adalah PO-2026-0001.', $result['answer']);
    }

    public function test_it_falls_back_to_openai_when_perplexity_requires_paid_credits(): void
    {
        config(['ai.huntr_assist.openai_fallback' => true]);

        $perplexity = Mockery::mock(PerplexityAgentService::class);
        $perplexity->shouldReceive('ask')
            ->once()
            ->andThrow(new PerplexityAgentException(
                'Paid credits required.',
                403,
                'perplexity_paid_credits_required',
            ));

        $openAi = Mockery::mock(OpenAiService::class);
        $openAi->shouldReceive('ask')
            ->once()
            ->withArgs(function (string $query, string $instruction, ?string $companyId, string $endpoint): bool {
                return $query === 'Bagaimana membuat PR?'
                    && str_contains($instruction, 'fallback without live web access')
                    && $companyId === null
                    && $endpoint === 'huntr-assist-openai-fallback';
            })
            ->andReturn('Buka Procurement lalu buat PR baru.');

        $platformTools = Mockery::mock(HuntrAssistToolGateway::class);

        $action = new AskHuntrAssistAction(
            $perplexity,
            Mockery::mock(AiQuotaService::class),
            $openAi,
            $platformTools,
        );

        $result = $action->execute('Bagaimana membuat PR?', null, null);

        $this->assertSame('openai', $result['provider']);
        $this->assertTrue($result['fallback']);
        $this->assertSame('perplexity_paid_credits_required', $result['fallback_reason']);
        $this->assertSame('Buka Procurement lalu buat PR baru.', $result['answer']);
        $this->assertSame([], $result['sources']);
    }
}
