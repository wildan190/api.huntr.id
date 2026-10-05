<?php

namespace App\Domain\Wms\Services;

use App\Domain\Wms\Support\WmsContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WmsAppService
{
    public function __construct(private WmsContext $context) {}

    public function apps(Request $request)
    {
        $company = $this->context->tenant($request, false);
        $installed = DB::table('company_apps')->where('company_id', $company->id)->where('app_key', 'wms-inventory')->whereNotNull('installed_at')->exists();

        return response()->json(['apps' => [['key' => 'wms-inventory', 'name' => 'WMS & Inventory', 'description' => 'Multiwarehouse, receiving, put away, allocation, packaging, reporting, and catalogue integration.', 'installed' => $installed]]]);
    }

    public function install(Request $request)
    {
        $company = $this->context->tenant($request, false);
        DB::table('company_apps')->updateOrInsert(['company_id' => $company->id, 'app_key' => 'wms-inventory'], ['installed_at' => now(), 'updated_at' => now(), 'created_at' => now()]);

        return response()->json(['message' => 'WMS & Inventory installed.', 'installed' => true]);
    }

    public function uninstall(Request $request)
    {
        $company = $this->context->tenant($request, false);
        DB::table('company_apps')->where('company_id', $company->id)->where('app_key', 'wms-inventory')->update(['installed_at' => null, 'updated_at' => now()]);

        return response()->json(['message' => 'WMS & Inventory disabled. Existing warehouse data is retained.', 'installed' => false]);
    }
}
