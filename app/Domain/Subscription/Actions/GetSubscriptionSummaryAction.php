<?php

namespace App\Domain\Subscription\Actions;

use App\Domain\Company\Models\Company;
use App\Domain\Subscription\Models\CompanySubscription;
use App\Domain\Subscription\Services\TaxQuotaService;

class GetSubscriptionSummaryAction
{
    public function __construct(private TaxQuotaService $taxQuota) {}

    public function execute(Company $company): ?array
    {
        $subscription = CompanySubscription::query()
            ->where('company_id', $company->id)
            ->where('starts_at', '<=', now())
            ->latest('starts_at')
            ->first();

        if (! $subscription) {
            return null;
        }

        return [
            'id' => $subscription->id,
            'plan' => $subscription->plan,
            'status' => $subscription->status,
            'enterprise_plan' => $subscription->enterprise_plan,
            'billing_cycle' => $subscription->billing_cycle,
            'tax_quota' => $subscription->enterprise_plan ? $this->taxQuota->summary($company) : null,
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
