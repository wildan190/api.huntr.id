<?php

namespace App\Domain\Order\Actions;

use App\Domain\Order\Models\PurchaseOrder;
use App\Domain\Company\Models\Company;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Action to fetch and map purchase orders based on company type and search criteria.
 */
class GetPurchaseOrdersAction
{
    /**
     * Execute the action.
     *
     * @param array $params
     * @return array
     */
    public function execute(array $params): array
    {
        $companyId = $params['company_id'];
        $perPage   = $params['per_page'] ?? 10;
        $search    = $params['search'] ?? null;
        
        $company = Company::findOrFail($companyId);

        $query = PurchaseOrder::with([
            'historicalItems', 
            'invoices', 
            'deliveryOrders', 
            'rfq.items.catalogue', 
            'vendor'
        ])->orderBy('id', 'desc');

        if ($company->type === 'buyer') {
            $query->where('buyer_company_id', $companyId);
        } else {
            $query->where('vendor_id', $companyId);
        }

        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('po_number', 'ilike', "%{$search}%")
                  ->orWhere('vendor_name', 'ilike', "%{$search}%")
                  ->orWhere('created_by', 'ilike', "%{$search}%")
                  ->orWhereHas('rfq', function ($rq) use ($search) {
                      $rq->where('title', 'ilike', "%{$search}%");
                  });
            });
        }

        $paginator = $query->paginate($perPage);

        return [
            'data'         => $this->mapItems($paginator->items()),
            'current_page' => $paginator->currentPage(),
            'last_page'    => $paginator->lastPage(),
            'total'        => $paginator->total(),
            'per_page'     => $paginator->perPage(),
        ];
    }

    /**
     * Map PO items to a standardized format for the frontend.
     */
    private function mapItems(array $items): array
    {
        return array_map(function ($po) {
            $mappedItems = collect();

            if ($po->is_historical) {
                $mappedItems = $po->historicalItems->map(function ($item) {
                    return [
                        'pr_reference_number' => $item->pr_reference_number,
                        'inventory_code'      => $item->inventory_code,
                        'inventory_name'      => $item->inventory_name,
                        'category'            => $item->category,
                        'uom'                 => $item->uom,
                        'qty'                 => $item->qty,
                        'unit_price'          => $item->unit_price,
                        'tax_amount'          => $item->tax_amount,
                        'total_amount'        => $item->total_amount,
                    ];
                });
            } else if ($po->rfq) {
                $mappedItems = $po->rfq->items->map(function ($item) {
                    $cat = $item->catalogue;
                    return [
                        'pr_reference_number' => 'RFQ-' . $item->rfq_id,
                        'inventory_code'      => $cat?->item_code ?? 'N/A',
                        'inventory_name'      => $cat?->name ?? 'N/A',
                        'category'            => $cat?->category ?? 'N/A',
                        'uom'                 => $cat?->uom ?? 'Pc',
                        'qty'                 => $item->qty,
                        'unit_price'          => $cat?->price ?? 0,
                        'tax_amount'          => 0,
                        'total_amount'        => $item->qty * ($cat?->price ?? 0),
                    ];
                });
            }

            return [
                'id'                => $po->id,
                'po_number'         => $po->po_number,
                'vendor_name'       => $po->vendor_name ?? $po->vendor?->name ?? 'N/A',
                'department'        => $po->department ?? 'N/A',
                'currency'          => $po->currency ?? 'IDR',
                'purchase_category' => $po->purchase_category ?? 'N/A',
                'purchase_type'     => $po->purchase_type ?? 'N/A',
                'order_date'        => $po->order_date?->format('Y-m-d') ?? $po->created_at->format('Y-m-d'),
                'status'            => $po->status,
                'is_historical'     => $po->is_historical,
                'created_by'        => $po->created_by ?? 'N/A',
                'approved_by'       => $po->approved_by ?? 'N/A',
                'total_amount'      => $mappedItems->sum('total_amount'),
                'items'             => $mappedItems,
            ];
        }, $items);
    }
}
