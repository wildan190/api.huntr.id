<?php

namespace App\Domain\Subscription\Services;

use App\Domain\Company\Models\Company;
use App\Domain\Subscription\Models\CompanySubscription;
use App\Domain\Subscription\Support\EnterprisePlanCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EnterpriseEntitlementService
{
    public function __construct(private EnterprisePlanCatalog $catalog) {}

    /** Call within a transaction that has locked the company row. */
    public function assertUserCapacity(Company $company): void
    {
        $limits = $this->limits($company);
        if ($limits === null) {
            $this->assertEnterpriseNotExpired($company);
            return;
        }

        $users = $company->users()->count();
        if (! $company->users()->whereKey($company->owner_id)->exists()) {
            $users++;
        }
        if ($users >= $limits['users']) {
            throw ValidationException::withMessages(['user' => 'Kuota pengguna paket telah penuh. Tambahkan add-on pengguna untuk mengundang anggota baru.']);
        }
    }

    /** Call within a transaction that has locked the company row. */
    public function assertWarehouseCapacity(Company $company): void
    {
        $limits = $this->limits($company);
        if ($limits === null) {
            $this->assertEnterpriseNotExpired($company);
            return;
        }

        $warehouses = DB::table('warehouses')->where('company_id', $company->id)->where('status', 'active')->count();
        if ($warehouses >= $limits['warehouses']) {
            throw ValidationException::withMessages(['warehouse' => 'Kuota warehouse paket telah penuh. Tambahkan add-on warehouse untuk lokasi baru.']);
        }
    }

    public function limits(Company $company): ?array
    {
        if ($company->type !== 'buyer') {
            return null;
        }
        $subscription = CompanySubscription::query()->where('company_id', $company->id)
            ->where('status', 'active')->whereNotNull('enterprise_plan')
            ->where('starts_at', '<=', now())->where('ends_at', '>', now())
            ->latest('starts_at')->first();
        $plan = $subscription ? array_replace(
            $this->catalog->find($subscription->enterprise_plan) ?? [],
            $subscription->plan_snapshot ?: [],
        ) : null;
        if (! $plan) {
            return null;
        }

        $extras = DB::table('company_subscription_addons')->where('company_id', $company->id)
            ->where('starts_at', '<=', now())->where(function ($query) {
                $query->whereNull('ends_at')->orWhere('ends_at', '>', now());
            })->selectRaw('addon_key, SUM(quantity) as total')->groupBy('addon_key')->pluck('total', 'addon_key');

        return [
            'users' => (int) $plan['users'] + (int) ($extras['extra_user'] ?? 0),
            'warehouses' => (int) $plan['warehouses'] + (int) ($extras['extra_warehouse'] ?? 0),
            'tax_documents_per_year' => (int) $plan['tax_documents_per_year'],
            'ai_requests_per_month' => (int) ($plan['ai_requests_per_month'] ?? 0),
        ];
    }

    private function assertEnterpriseNotExpired(Company $company): void
    {
        if ($company->type === 'buyer' && CompanySubscription::query()->where('company_id', $company->id)->whereNotNull('enterprise_plan')->exists()) {
            throw ValidationException::withMessages(['subscription' => 'Paket Enterprise tidak aktif. Perbarui paket untuk menambah pengguna atau warehouse.']);
        }
    }
}
