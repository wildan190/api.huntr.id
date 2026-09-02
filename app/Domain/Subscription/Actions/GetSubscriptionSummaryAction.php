<?php

namespace App\Domain\Subscription\Actions;

use App\Domain\Company\Models\Company;
use App\Domain\Subscription\Models\CompanySubscription;

class GetSubscriptionSummaryAction
{
    public function execute(Company $company): ?array
    {
        $subscription = CompanySubscription::query()
            ->where('company_id', $company->id)
            ->latest('starts_at')
            ->first();

        if (! $subscription) {
            return null;
        }

        return [
            'id' => $subscription->id,
            'plan' => $subscription->plan,
            'status' => $subscription->status,
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
