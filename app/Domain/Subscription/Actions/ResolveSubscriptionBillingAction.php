<?php

namespace App\Domain\Subscription\Actions;

use App\Domain\Company\Models\Company;
use App\Domain\Subscription\Exceptions\SubscriptionRenewalRequiredException;
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

        $usedOrReserved = (float) $subscription->current_realized_gmv + (float) $subscription->reserved_gmv;
        if ($usedOrReserved + $transactionAmount <= (float) $subscription->gmv_limit) {
            $subscription->increment('reserved_gmv', $transactionAmount);

            return [
                'billing_mode' => 'subscription_quota',
                'subscription_id' => $subscription->id,
                'gmv_credited_amount' => $transactionAmount,
                'waive_platform_fee' => true,
            ];
        }

        if ($subscription->overflow_strategy === CompanySubscription::OVERFLOW_RENEWAL_REQUIRED) {
            $subscription->update(['status' => 'renewal_required']);

            throw new SubscriptionRenewalRequiredException(
                'Kuota GMV subscription tidak mencukupi. Silakan perpanjang atau upgrade kontrak terlebih dahulu.'
            );
        }

        return $this->transactionFeeDecision();
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
