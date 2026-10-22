<?php

namespace App\Domain\Subscription\Actions;

use App\Domain\Company\Models\Company;
use App\Domain\Subscription\Models\CompanySubscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RevokeEnterpriseEntitlementAction
{
    /**
     * Revoke the current and any scheduled Enterprise entitlement while keeping
     * the subscription records available for billing and audit history.
     */
    public function execute(Company $company): int
    {
        if ($company->type !== 'buyer') {
            throw ValidationException::withMessages([
                'company' => 'Paket Enterprise hanya berlaku untuk perusahaan buyer.',
            ]);
        }

        return DB::transaction(function () use ($company): int {
            Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
            $now = now();
            $subscriptions = CompanySubscription::query()
                ->where('company_id', $company->id)
                ->whereNotNull('enterprise_plan')
                ->where('status', 'active')
                ->get();

            if ($subscriptions->isEmpty()) {
                throw ValidationException::withMessages([
                    'subscription' => 'Tidak ada hak paket Enterprise aktif yang dapat dicabut.',
                ]);
            }

            foreach ($subscriptions as $subscription) {
                $attributes = [
                    'status' => 'revoked',
                    'updated_at' => $now,
                ];

                if ($subscription->starts_at?->lessThanOrEqualTo($now)) {
                    $attributes['ends_at'] = $now;
                }

                $subscription->update($attributes);
            }

            return $subscriptions->count();
        });
    }
}
