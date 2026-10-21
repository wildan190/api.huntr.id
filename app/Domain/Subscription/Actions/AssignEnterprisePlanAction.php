<?php

namespace App\Domain\Subscription\Actions;

use App\Domain\Company\Models\Company;
use App\Domain\Subscription\Models\CompanySubscription;
use App\Domain\Subscription\Support\EnterprisePlanCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AssignEnterprisePlanAction
{
    public function __construct(private EnterprisePlanCatalog $catalog) {}

    /**
     * Direct assignment is reserved for an administrator correcting or setting
     * a buyer's commercial entitlement. It deliberately replaces any live or
     * scheduled Enterprise entitlement so only one package is effective.
     */
    public function execute(Company $company, string $planKey, string $billingCycle): CompanySubscription
    {
        if ($company->type !== 'buyer') {
            throw ValidationException::withMessages([
                'company' => 'Paket Enterprise hanya dapat diterapkan untuk perusahaan buyer.',
            ]);
        }

        $plan = $this->catalog->find($planKey);
        if (! $plan) {
            throw ValidationException::withMessages(['enterprise_plan' => 'Paket Enterprise tidak tersedia.']);
        }

        return DB::transaction(function () use ($company, $plan, $billingCycle) {
            Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
            $startsAt = now();

            CompanySubscription::query()
                ->where('company_id', $company->id)
                ->whereNotNull('enterprise_plan')
                ->where('status', 'active')
                ->where('ends_at', '>', $startsAt)
                ->update([
                    'status' => 'replaced',
                    'ends_at' => $startsAt,
                    'updated_at' => $startsAt,
                ]);

            return CompanySubscription::create([
                'company_id' => $company->id,
                'plan' => 'saas_subscription',
                'enterprise_plan' => $plan['key'],
                'billing_cycle' => $billingCycle,
                'plan_snapshot' => $plan,
                'is_trial' => false,
                'status' => 'active',
                'overflow_strategy' => CompanySubscription::OVERFLOW_RENEWAL_REQUIRED,
                'upfront_fee' => $plan[$billingCycle === 'annual' ? 'annual_price' : 'monthly_price'],
                'gmv_limit' => 0,
                'current_realized_gmv' => 0,
                'reserved_gmv' => 0,
                'starts_at' => $startsAt,
                'ends_at' => $billingCycle === 'annual'
                    ? $startsAt->copy()->addYear()
                    : $startsAt->copy()->addMonthNoOverflow(),
            ]);
        });
    }
}
