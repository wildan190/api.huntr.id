<?php

namespace App\Domain\AI\Services;

use App\Domain\AI\Models\AiUsageLog;
use App\Domain\Company\Models\Company;
use App\Domain\Subscription\Services\EnterpriseEntitlementService;
use Illuminate\Validation\ValidationException;

class AiQuotaService
{
    public function __construct(private EnterpriseEntitlementService $entitlements) {}

    public function assertAvailable(string $companyId): void
    {
        $summary = $this->summaryById($companyId);

        if ($summary === null || $summary['limit'] === null) {
            return;
        }

        if ($summary['remaining'] <= 0) {
            throw ValidationException::withMessages([
                'ai_quota' => sprintf(
                    'Kuota request AI paket %s untuk bulan ini telah habis (%s request). Upgrade paket atau tunggu periode berikutnya.',
                    $summary['plan_name'],
                    number_format($summary['limit'], 0, ',', '.')
                ),
            ]);
        }
    }

    public function recordExternalRequest(string $companyId, string $endpoint, string $model = 'gemini'): void
    {
        AiUsageLog::create([
            'company_id' => $companyId,
            'user_id' => null,
            'endpoint' => $endpoint,
            'model' => $model,
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'total_tokens' => 0,
            'estimated_cost_usd' => 0,
        ]);
    }

    public function summary(Company $company): ?array
    {
        $limits = $this->entitlements->limits($company);
        if ($limits === null) {
            return null;
        }

        $used = AiUsageLog::getMonthlySummary($company->id);
        $limit = (int) ($limits['ai_requests_per_month'] ?? 0);

        return array_merge($used, [
            'limit' => $limit,
            'remaining' => max(0, $limit - $used['total_requests']),
            'plan_name' => $this->planName($company),
            'period' => now()->startOfMonth()->toIso8601String(),
        ]);
    }

    public function summaryById(string $companyId): ?array
    {
        return $this->summary(Company::query()->findOrFail($companyId));
    }

    private function planName(Company $company): string
    {
        $subscription = $company->subscriptions()
            ->where('status', 'active')
            ->whereNotNull('enterprise_plan')
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>', now())
            ->latest('starts_at')
            ->first();

        return (string) ($subscription?->plan_snapshot['name'] ?? str($subscription?->enterprise_plan ?? 'Enterprise')->replace('_', ' ')->title());
    }
}
