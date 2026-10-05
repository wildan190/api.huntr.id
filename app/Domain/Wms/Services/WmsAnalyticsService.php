<?php

namespace App\Domain\Wms\Services;

use App\Domain\Wms\Support\WmsContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WmsAnalyticsService
{
    public function __construct(private WmsContext $context) {}

    public function dashboard(Request $request)
    {
        $company = $this->context->tenant($request);
        $stock = DB::table('warehouse_stock')->where('company_id', $company->id);

        return response()->json([
            'warehouses' => DB::table('warehouses')->where('company_id', $company->id)->where('status', 'active')->count(),
            'sku_count' => (clone $stock)->distinct('sku')->count('sku'),
            'on_hand_units' => (float) (clone $stock)->sum('on_hand'),
            'allocated_units' => (float) (clone $stock)->sum('allocated'),
            'low_stock' => (clone $stock)->where('reorder_level', '>', 0)->whereRaw('(on_hand - allocated) <= reorder_level')->count(),
            'recent_transactions' => DB::table('warehouse_transactions')->where('company_id', $company->id)->orderByDesc('id')->limit(8)->get(),
            'orders' => DB::table('warehouse_orders')->where('company_id', $company->id)->orderByDesc('id')->limit(8)->get(),
        ]);
    }

    public function report(Request $request)
    {
        $company = $this->context->tenant($request);

        return response()->json([
            'stock_by_warehouse' => DB::table('warehouse_stock')->join('warehouses', 'warehouses.id', '=', 'warehouse_stock.warehouse_id')->where('warehouse_stock.company_id', $company->id)->groupBy('warehouses.id', 'warehouses.code', 'warehouses.name')->select('warehouses.id', 'warehouses.code', 'warehouses.name', DB::raw('SUM(warehouse_stock.on_hand) as on_hand'), DB::raw('SUM(warehouse_stock.allocated) as allocated'), DB::raw('COUNT(DISTINCT warehouse_stock.sku) as sku_count'))->get(),
            'movement_summary' => DB::table('warehouse_transactions')->where('company_id', $company->id)->where('created_at', '>=', now()->subDays(30))->groupBy('type')->select('type', DB::raw('COUNT(*) as transactions'), DB::raw('SUM(quantity) as quantity'))->get(),
            'orders_by_status' => DB::table('warehouse_orders')->where('company_id', $company->id)->groupBy('status')->select('status', DB::raw('COUNT(*) as total'))->get(),
            'catalogue_linked_skus' => DB::table('warehouse_stock')->where('company_id', $company->id)->whereNotNull('catalogue_id')->count(),
            'low_stock_items' => DB::table('warehouse_stock as s')->join('warehouses as w', 'w.id', '=', 's.warehouse_id')->where('s.company_id', $company->id)->where('s.reorder_level', '>', 0)->whereRaw('(s.on_hand - s.allocated) <= s.reorder_level')->orderByRaw('(s.on_hand - s.allocated) asc')->select('s.sku', 's.item_name', 's.bin_location', 's.on_hand', 's.allocated', 's.reorder_level', DB::raw('(s.on_hand - s.allocated) as available'), 'w.name as warehouse_name')->limit(100)->get(),
        ]);
    }
}
