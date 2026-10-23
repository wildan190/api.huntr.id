<?php

namespace App\Domain\Budget\Services;

use App\Domain\Budget\Support\BudgetContext;
use App\Domain\Company\Services\CompanyAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BudgetAppService
{
    public function __construct(private BudgetContext $context, private CompanyAccessService $access) {}

    public function apps(Request $request)
    {
        $company = $this->context->tenant($request, false);
        $eligible = $this->context->hasProEntitlement($company);
        $accessible = false;
        if ($eligible) {
            $this->access->bootstrap($company);
            $accessible = $this->access->can($request->user(), $company, 'budget.access');
        }

        return response()->json(['apps' => [[
            'key' => 'budget-procurement-plan', 'name' => 'Budgeting & Procurement Plan',
            'description' => 'Budget cycle, alokasi, procurement plan, approval, dan pengendalian komitmen PR/PO.',
            'installed' => $eligible && $this->context->isInstalled($company), 'eligible' => $eligible, 'accessible' => $accessible,
            'required_plan' => 'enterprise_pro',
        ]]]);
    }

    public function install(Request $request)
    {
        $company = $this->context->tenant($request, false);
        abort_unless($this->context->hasProEntitlement($company), 422, 'Budgeting & Procurement Plan hanya dapat diinstall pada Enterprise Pro aktif.');
        DB::table('company_apps')->updateOrInsert(['company_id' => $company->id, 'app_key' => 'budget-procurement-plan'], ['installed_at' => now(), 'updated_at' => now(), 'created_at' => now()]);

        return response()->json(['message' => 'Budgeting & Procurement Plan berhasil diinstall.', 'installed' => true]);
    }

    public function uninstall(Request $request)
    {
        $company = $this->context->tenant($request, false);
        DB::table('company_apps')->where('company_id', $company->id)->where('app_key', 'budget-procurement-plan')->update(['installed_at' => null, 'updated_at' => now()]);

        return response()->json(['message' => 'Budgeting & Procurement Plan dinonaktifkan. Data anggaran tetap tersimpan.', 'installed' => false]);
    }
}
