<?php

namespace App\Domain\Budget\Support;

use App\Domain\Company\Models\Company;
use App\Domain\Company\Services\CompanyAccessService;
use App\Domain\Subscription\Models\CompanySubscription;
use App\Domain\Subscription\Services\SubscriptionAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BudgetContext
{
    public function __construct(private SubscriptionAccessService $subscriptionAccess, private CompanyAccessService $access) {}

    public function tenant(Request $request, bool $requireInstalled = true): Company
    {
        $user = $request->user();
        abort_unless($user, 401);
        $company = Company::query()->find($request->input('company_id', $request->query('company_id')));
        abort_unless($company && ((string) $company->owner_id === (string) $user->id || $company->users()->whereKey($user->id)->exists()), 403, 'Anda tidak memiliki akses ke perusahaan ini.');

        if ($requireInstalled) {
            $this->subscriptionAccess->assertAvailable($company);
            abort_unless($this->hasProEntitlement($company), 403, 'Budgeting & Procurement Plan hanya tersedia untuk Enterprise Pro aktif.');
            abort_unless($this->isInstalled($company), 403, 'Install Budgeting & Procurement Plan dari App Market terlebih dahulu.');
            $this->access->bootstrap($company);
            abort_unless($this->access->can($user, $company, 'budget.access'), 403, 'Akses Budgeting belum diberikan pada role Anda.');
        }

        return $company;
    }

    public function hasProEntitlement(Company $company): bool
    {
        return $company->type === 'buyer' && CompanySubscription::query()->where('company_id', $company->id)
            ->where('enterprise_plan', 'enterprise_pro')->where('status', 'active')
            ->where('starts_at', '<=', now())->where('ends_at', '>', now())->exists();
    }

    public function isInstalled(Company $company): bool
    {
        return DB::table('company_apps')->where('company_id', $company->id)
            ->where('app_key', 'budget-procurement-plan')->whereNotNull('installed_at')->exists();
    }
}
