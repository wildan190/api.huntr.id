<?php

namespace App\Domain\Subscription\Actions;

use App\Domain\Company\Models\Company;
use App\Domain\Subscription\Models\CompanySubscription;
use App\Domain\Subscription\Support\EnterprisePlanCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GrantEnterpriseTrialAction
{
    public const DURATION_DAYS = 14;

    public function __construct(private EnterprisePlanCatalog $catalog) {}

    /** @return array{subscription: CompanySubscription, is_retrial: bool} */
    public function execute(Company $company): array
    {
        if ($company->type !== 'buyer') {
            throw ValidationException::withMessages([
                'company' => 'Trial Enterprise hanya dapat diberikan untuk perusahaan buyer.',
            ]);
        }

        $plan = $this->catalog->find('enterprise_pro');
        abort_unless($plan, 503, 'Konfigurasi Enterprise Pro belum tersedia.');

        return DB::transaction(function () use ($company, $plan) {
            Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
            $startsAt = now();
            $isRetrial = CompanySubscription::query()
                ->where('company_id', $company->id)
                ->whereNotNull('enterprise_plan')
                ->exists();

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

            $trial = CompanySubscription::create([
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
                'current_realized_gmv' => 0,
                'reserved_gmv' => 0,
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->copy()->addDays(self::DURATION_DAYS),
            ]);

            return ['subscription' => $trial, 'is_retrial' => $isRetrial];
        });
    }
}
