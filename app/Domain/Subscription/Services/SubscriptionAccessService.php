<?php

namespace App\Domain\Subscription\Services;

use App\Domain\Company\Models\Company;
use App\Domain\Subscription\Models\CompanySubscription;
use Illuminate\Http\Exceptions\HttpResponseException;

class SubscriptionAccessService
{
    public function status(Company $company): array
    {
        if ($company->type !== 'buyer') {
            return ['overdue' => false, 'overdue_since' => null, 'active_enterprise' => false];
        }

        $base = CompanySubscription::query()->where('company_id', $company->id)->where('starts_at', '<=', now());
        $active = (clone $base)->where('status', 'active')->where('ends_at', '>', now())->latest('starts_at')->first();
        if ($active) {
            return ['overdue' => false, 'overdue_since' => null, 'active_enterprise' => (bool) $active->enterprise_plan];
        }

        $latest = (clone $base)->latest('starts_at')->first();
        $overdue = $latest && $latest->enterprise_plan && $latest->ends_at && $latest->ends_at->lessThanOrEqualTo(now());

        return [
            'overdue' => (bool) $overdue,
            'overdue_since' => $overdue ? $latest->ends_at->toIso8601String() : null,
            'active_enterprise' => false,
        ];
    }

    public function assertAvailable(Company $company): void
    {
        $status = $this->status($company);
        if ($status['overdue']) {
            throw new HttpResponseException(response()->json([
                'error' => 'subscription_overdue',
                'message' => 'Langganan Enterprise telah berakhir. Perpanjang paket untuk kembali mengakses WMS dan membuat PR.',
                'overdue_since' => $status['overdue_since'],
            ], 403));
        }
    }
}
