<?php

namespace App\Domain\Subscription\Actions;

use App\Domain\Auth\Models\User;
use App\Domain\Company\Models\Company;
use App\Domain\Subscription\Models\CompanySubscription;
use App\Domain\Subscription\Support\EnterprisePlanCatalog;

class StartEnterpriseTrialAction
{
    public const DURATION_DAYS = 14;

    public function __construct(private EnterprisePlanCatalog $catalog) {}

    public function execute(User $user, Company $company): ?CompanySubscription
    {
        if ($company->type !== 'buyer') {
            return null;
        }

        $alreadyReceivedTrial = CompanySubscription::query()
            ->join('companies', 'companies.id', '=', 'company_subscriptions.company_id')
            ->where('companies.owner_id', $user->id)
            ->where('companies.type', 'buyer')
            ->whereNotNull('company_subscriptions.enterprise_plan')
            ->exists();

        if ($alreadyReceivedTrial) {
            return null;
        }

        $plan = $this->catalog->find('enterprise_pro');
        abort_unless($plan, 503, 'Konfigurasi Enterprise Pro belum tersedia.');

        $startsAt = now();

        return CompanySubscription::create([
            'company_id' => $company->id,
            'plan' => 'enterprise_trial',
            'enterprise_plan' => $plan['key'],
            'billing_cycle' => 'trial',
            'plan_snapshot' => $plan,
            'is_trial' => true,
            'status' => 'active',
            'overflow_strategy' => CompanySubscription::OVERFLOW_TRANSACTION_FEE,
            'upfront_fee' => 0,
            'gmv_limit' => 0,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addDays(self::DURATION_DAYS),
        ]);
    }
}
