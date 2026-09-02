<?php

namespace App\Domain\Subscription\Actions;

use App\Domain\Company\Models\Company;
use App\Domain\Subscription\Models\CompanySubscription;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Activates a paid annual GMV contract from a trusted backoffice workflow. */
class ActivateCompanySubscriptionAction
{
    public function execute(
        Company $company,
        float $gmvLimit,
        string $overflowStrategy = CompanySubscription::OVERFLOW_TRANSACTION_FEE,
        ?Carbon $startsAt = null,
    ): CompanySubscription {
        if ($gmvLimit <= 0) {
            throw new InvalidArgumentException('Kuota GMV harus lebih besar dari nol.');
        }

        if (! in_array($overflowStrategy, [
            CompanySubscription::OVERFLOW_TRANSACTION_FEE,
            CompanySubscription::OVERFLOW_RENEWAL_REQUIRED,
        ], true)) {
            throw new InvalidArgumentException('Strategi overflow subscription tidak valid.');
        }

        $startsAt ??= now();

        return DB::transaction(function () use ($company, $gmvLimit, $overflowStrategy, $startsAt): CompanySubscription {
            CompanySubscription::query()
                ->where('company_id', $company->id)
                ->where('status', 'active')
                ->lockForUpdate()
                ->update(['status' => 'superseded']);

            return CompanySubscription::create([
                'company_id' => $company->id,
                'plan' => 'gmv_subscription',
                'status' => 'active',
                'overflow_strategy' => $overflowStrategy,
                'upfront_fee' => round($gmvLimit * CompanySubscription::UPFRONT_RATE, 2),
                'gmv_limit' => $gmvLimit,
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->copy()->addYear(),
            ]);
        });
    }
}
