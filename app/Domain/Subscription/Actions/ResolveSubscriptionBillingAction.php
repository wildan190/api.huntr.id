<?php

namespace App\Domain\Subscription\Actions;

use App\Domain\Company\Models\Company;
use App\Domain\Subscription\Models\CompanySubscription;

/**
 * Decides the billing mode for one PO and reserves GMV quota atomically.
 * This action must be called from the transaction that creates its invoice.
 */
class ResolveSubscriptionBillingAction
{
    /** @return array{billing_mode: string, subscription_id: ?string, gmv_credited_amount: float, waive_platform_fee: bool} */
    public function execute(Company $company, float $transactionAmount): array
    {
        $subscription = CompanySubscription::query()
            ->where('company_id', $company->id)
            ->where('status', 'active')
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>', now())
            ->latest('starts_at')
            ->lockForUpdate()
            ->first();

        if (! $subscription) {
            return $this->transactionFeeDecision();
        }

        if ($subscription->plan === 'saas_subscription' || $subscription->plan === 'enterprise_trial' || $subscription->is_trial) {
            return [
                'billing_mode' => 'saas_subscription',
                'subscription_id' => $subscription->id,
                'gmv_credited_amount' => $transactionAmount,
                'waive_platform_fee' => true,
            ];
        }

        if ($subscription->plan === 'per_po') {
            return [
                'billing_mode' => 'per_po',
                'subscription_id' => $subscription->id,
                'gmv_credited_amount' => 0,
                'waive_platform_fee' => false,
            ];
        }

        // Under SaaS subscription model, active subscriptions (SaaS / Enterprise / Trial)
        // waive the platform fee without being restricted by legacy GMV quotas.
        return [
            'billing_mode' => 'saas_subscription',
            'subscription_id' => $subscription->id,
            'gmv_credited_amount' => $transactionAmount,
            'waive_platform_fee' => true,
        ];
    }

    /** @return array{billing_mode: string, subscription_id: null, gmv_credited_amount: float, waive_platform_fee: bool} */
    private function transactionFeeDecision(): array
    {
        return [
            'billing_mode' => 'transaction_fee',
            'subscription_id' => null,
            'gmv_credited_amount' => 0,
            'waive_platform_fee' => false,
        ];
    }
}
