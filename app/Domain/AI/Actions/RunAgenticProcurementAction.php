<?php

namespace App\Domain\AI\Actions;

use App\Domain\AI\Services\AgenticProcurementService;

class RunAgenticProcurementAction
{
    public function __construct(
        private readonly AgenticProcurementService $agenticService
    ) {}

    public function execute(string $query, array $options = []): array
    {
        return $this->agenticService->runFullWorkflow($query, $options);
    }
}
