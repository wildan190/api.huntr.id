<?php

namespace App\Domain\AI\Actions;

use App\Domain\AI\Services\AgenticProcurementService;
use App\Domain\Company\Models\Company;
use App\Domain\Rfq\Models\Rfq;

class CreateAgenticPrAction
{
    public function __construct(
        private readonly AgenticProcurementService $agenticService
    ) {}

    public function execute(string $companyId, array $prDraft, ?string $userId = null): Rfq
    {
        $company = Company::findOrFail($companyId);
        if ($company->type !== 'buyer') {
            throw new \InvalidArgumentException('Hanya perusahaan Buyer yang dapat membuat Purchase Requisition.');
        }

        return $this->agenticService->createPrFromDraft($company, $prDraft, $userId);
    }
}
