<?php

namespace App\Domain\Crm\Services;

use App\Domain\Crm\Support\CrmContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CrmAppService
{
    public function __construct(private CrmContext $context) {}

    public function apps(Request $request)
    {
        $company = $this->context->tenant($request, false);
        $eligible = $this->context->hasProEntitlement($company);

        return response()->json(['apps' => [[
            'key' => 'crm',
            'name' => 'CRM Enterprise',
            'description' => 'Customer 360, sales pipeline, automation, helpdesk, CPQ, territory, and AI forecasting.',
            'installed' => $eligible && $this->context->isInstalled($company),
            'eligible' => $eligible,
            'required_plan' => 'enterprise_pro',
        ]]]);
    }

    public function install(Request $request)
    {
        $company = $this->context->tenant($request, false);
        abort_unless($this->context->hasProEntitlement($company), 422, 'CRM hanya dapat diinstall pada Enterprise Pro aktif.');
        DB::table('company_apps')->updateOrInsert(
            ['company_id' => $company->id, 'app_key' => 'crm'],
            ['installed_at' => now(), 'updated_at' => now(), 'created_at' => now()],
        );

        return response()->json(['message' => 'CRM Enterprise berhasil diinstall.', 'installed' => true]);
    }

    public function uninstall(Request $request)
    {
        $company = $this->context->tenant($request, false);
        DB::table('company_apps')->where('company_id', $company->id)->where('app_key', 'crm')
            ->update(['installed_at' => null, 'updated_at' => now()]);

        return response()->json(['message' => 'CRM Enterprise dinonaktifkan. Data CRM tetap tersimpan.', 'installed' => false]);
    }
}
