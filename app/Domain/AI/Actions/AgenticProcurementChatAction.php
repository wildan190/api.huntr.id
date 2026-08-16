<?php

namespace App\Domain\AI\Actions;

use App\Domain\AI\Services\AgenticProcurementService;

class AgenticProcurementChatAction
{
    public function __construct(
        private readonly AgenticProcurementService $agenticService
    ) {}

    public function execute(array $messages, array $options = []): array
    {
        return $this->agenticService->chatWithAgent($messages, $options);
    }
}
