<?php

namespace App\Domain\Subscription\Services;

use App\Domain\Company\Models\Company;
use App\Domain\Subscription\Models\CompanySubscription;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TaxQuotaService
{
    public function __construct(private EnterpriseEntitlementService $entitlements) {}

    /** Call while holding the company row lock. */
    public function period(Company $company): object
    {
        $first = CompanySubscription::query()->where('company_id', $company->id)
            ->whereNotNull('enterprise_plan')->where('starts_at', '<=', now())->oldest('starts_at')->firstOrFail();
        $start = Carbon::parse($first->starts_at);
        $end = $start->copy()->addYearNoOverflow();
        while ($end->lessThanOrEqualTo(now())) {
            $start = $end;
            $end = $start->copy()->addYearNoOverflow();
        }
        $existing = DB::table('company_tax_quota_periods')->where('company_id', $company->id)
            ->where('starts_at', $start)->first();
        if ($existing) {
            return $existing;
        }
        $id = (string) Str::uuid();
        DB::table('company_tax_quota_periods')->insert([
            'id' => $id, 'company_id' => $company->id, 'starts_at' => $start, 'ends_at' => $end,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return DB::table('company_tax_quota_periods')->where('id', $id)->first();
    }

    public function reserve(string $companyId, string $bastId): ?string
    {
        return DB::transaction(function () use ($companyId, $bastId) {
            $company = Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $limits = $this->entitlements->limits($company);
            if (! $limits) {
                if (CompanySubscription::query()->where('company_id', $company->id)->whereNotNull('enterprise_plan')->exists()) {
                    throw ValidationException::withMessages(['efaktur' => 'Paket Enterprise buyer tidak aktif. Perbarui paket untuk menerbitkan e-Faktur.']);
                }
                return null;
            }
            if (DB::table('company_tax_quota_usage')->where('bast_id', $bastId)->exists()) {
                throw ValidationException::withMessages(['efaktur' => 'Penerbitan e-Faktur untuk BAST ini sedang diproses atau perlu diperiksa oleh tim Huntr.']);
            }
            $period = $this->period($company);
            $topup = DB::table('company_subscription_addons')->where('tax_quota_period_id', $period->id)
                ->where('addon_key', 'tax_documents')->where('starts_at', '<=', now())->where('ends_at', '>', now())->sum('quantity');
            $used = DB::table('company_tax_quota_usage')->where('tax_quota_period_id', $period->id)->count();
            if ($used >= $limits['tax_documents_per_year'] + $topup) {
                throw ValidationException::withMessages(['efaktur' => 'Kuota e-Faktur buyer telah habis. Tambahkan kuota dokumen pajak.']);
            }
            $id = (string) Str::uuid();
            DB::table('company_tax_quota_usage')->insert([
                'id' => $id, 'company_id' => $company->id, 'tax_quota_period_id' => $period->id,
                'bast_id' => $bastId, 'status' => 'reserved', 'created_at' => now(), 'updated_at' => now(),
            ]);
            return $id;
        });
    }

    public function finish(?string $id, ?string $efakturId): void
    {
        if ($id) {
            DB::table('company_tax_quota_usage')->where('id', $id)->update([
                'status' => $efakturId ? 'consumed' : 'uncertain', 'efaktur_id' => $efakturId, 'updated_at' => now(),
            ]);
        }
    }

    public function summary(Company $company): ?array
    {
        return DB::transaction(function () use ($company) {
            $locked = Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
            $limits = $this->entitlements->limits($locked);
            if (! $limits) {
                return null;
            }
            $period = $this->period($locked);
            $topup = (int) DB::table('company_subscription_addons')->where('tax_quota_period_id', $period->id)
                ->where('addon_key', 'tax_documents')->where('starts_at', '<=', now())->where('ends_at', '>', now())->sum('quantity');
            $used = DB::table('company_tax_quota_usage')->where('tax_quota_period_id', $period->id)->count();
            return ['limit' => $limits['tax_documents_per_year'] + $topup, 'used' => $used,
                'ends_at' => Carbon::parse($period->ends_at)->toIso8601String()];
        });
    }
}
