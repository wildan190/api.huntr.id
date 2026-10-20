<?php

namespace App\Domain\Subscription\Services;

use App\Domain\Auth\Models\User;
use App\Domain\Company\Models\Company;
use App\Domain\Subscription\Models\CompanySubscription;
use App\Domain\Subscription\Support\EnterprisePlanCatalog;
use Illuminate\Validation\ValidationException;

class CompanyCapacityService
{
    public function __construct(private EnterprisePlanCatalog $catalog) {}

    public function assertCanCreate(User $user): void
    {
        $summary = $this->summary($user);

        if ($summary['used'] >= $summary['limit']) {
            throw ValidationException::withMessages([
                'company' => sprintf(
                    'Kuota company paket %s telah penuh (%d dari %d). Upgrade paket untuk menambah company.',
                    $summary['plan_name'],
                    $summary['used'],
                    $summary['limit'],
                ),
            ]);
        }
    }

    public function summary(User $user): array
    {
        $used = Company::query()
            ->where('owner_id', $user->id)
            ->where('status', '!=', 'rejected')
            ->count();

        $plans = CompanySubscription::query()
            ->select('company_subscriptions.*')
            ->join('companies', 'companies.id', '=', 'company_subscriptions.company_id')
            ->where('companies.owner_id', $user->id)
            ->where('companies.type', 'buyer')
            ->where('company_subscriptions.status', 'active')
            ->whereNotNull('company_subscriptions.enterprise_plan')
            ->where('company_subscriptions.starts_at', '<=', now())
            ->where('company_subscriptions.ends_at', '>', now())
            ->get()
            ->map(fn (CompanySubscription $subscription) => $this->planCapacity($subscription));

        $selected = $plans->sortByDesc('limit')->first() ?? $this->defaultCapacity();

        return [
            'used' => $used,
            'limit' => $selected['limit'],
            'remaining' => max(0, $selected['limit'] - $used),
            'plan_key' => $selected['key'],
            'plan_name' => $selected['name'],
        ];
    }

    private function planCapacity(CompanySubscription $subscription): array
    {
        $catalogPlan = $this->catalog->find($subscription->enterprise_plan);
        $plan = $subscription->plan_snapshot ?: $catalogPlan;

        return [
            'key' => $subscription->enterprise_plan,
            'name' => $plan['name'] ?? $catalogPlan['name'] ?? 'Enterprise Starter',
            'limit' => (int) ($plan['companies'] ?? $catalogPlan['companies'] ?? 1),
        ];
    }

    private function defaultCapacity(): array
    {
        $starter = $this->catalog->find('enterprise_starter');

        return [
            'key' => $starter['key'] ?? 'enterprise_starter',
            'name' => $starter['name'] ?? 'Enterprise Starter',
            'limit' => (int) ($starter['companies'] ?? 1),
        ];
    }
}
