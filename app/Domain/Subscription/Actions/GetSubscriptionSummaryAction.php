<?php

namespace App\Domain\Subscription\Actions;

use App\Domain\Company\Models\Company;
use App\Domain\Subscription\Models\CompanySubscription;
use App\Domain\Subscription\Services\TaxQuotaService;
use App\Domain\AI\Services\AiQuotaService;

class GetSubscriptionSummaryAction
{
    public function __construct(
        private TaxQuotaService $taxQuota,
        private AiQuotaService $aiQuota,
    ) {}

    public function execute(Company $company): ?array
    {
        $subscription = CompanySubscription::query()
            ->where('company_id', $company->id)
            ->where('status', 'active')
            ->where('starts_at', '<=', now())
            ->latest('starts_at')
            ->first();

        $subscription ??= CompanySubscription::query()
            ->where('company_id', $company->id)
            ->where('starts_at', '<=', now())
            ->latest('starts_at')
            ->first();

        if (! $subscription) {
            return null;
        }

        $scheduled = CompanySubscription::query()
            ->where('company_id', $company->id)
            ->where('status', 'active')
            ->whereNotNull('enterprise_plan')
            ->where('starts_at', '>', now())
            ->oldest('starts_at')
            ->first();

        return [
            'id' => $subscription->id,
            'plan' => $subscription->plan,
            'status' => $subscription->status,
            'enterprise_plan' => $subscription->enterprise_plan,
            'billing_cycle' => $subscription->billing_cycle,
            'is_trial' => $subscription->is_trial,
            'scheduled_change' => $scheduled ? [
                'enterprise_plan' => $scheduled->enterprise_plan,
                'starts_at' => $scheduled->starts_at?->toIso8601String(),
            ] : null,
            'tax_quota' => $subscription->enterprise_plan ? $this->taxQuota->summary($company) : null,
            'ai_quota' => $subscription->enterprise_plan ? $this->aiQuota->summary($company) : null,
            'overflow_strategy' => $subscription->overflow_strategy,
            'upfront_fee' => (float) $subscription->upfront_fee,
            'gmv_limit' => (float) $subscription->gmv_limit,
            'current_realized_gmv' => (float) $subscription->current_realized_gmv,
            'reserved_gmv' => (float) $subscription->reserved_gmv,
            'available_gmv' => $subscription->remainingGmv(),
            'starts_at' => $subscription->starts_at?->toIso8601String(),
            'ends_at' => $subscription->ends_at?->toIso8601String(),
        ];
    }
}
