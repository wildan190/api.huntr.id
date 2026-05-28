<?php

namespace App\Domain\Order\Http\Controllers;

use App\Domain\Order\Actions\AwardVendorAction;
use App\Domain\Order\Http\Requests\AwardVendorRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Domain\Rfq\Models\Rfq;
use App\Domain\Proposal\Models\Proposal;
use App\Domain\Auth\Models\User;
use App\Domain\Order\Models\PurchaseOrder;

class OrderController extends \App\Http\Controllers\Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'company_id' => 'required|exists:companies,id',
            'per_page'   => 'integer|min:1|max:100',
        ]);

        $companyId = $request->input('company_id');
        $perPage   = $request->input('per_page', 10);
        $company   = \App\Domain\Company\Models\Company::findOrFail($companyId);

        $query = PurchaseOrder::with(['historicalItems', 'invoices', 'deliveryOrders', 'rfq.items.catalogue', 'vendor'])
            ->orderBy('id', 'desc');

        if ($company->type === 'buyer') {
            $query->where('buyer_company_id', $companyId);
        } else {
            $query->where('vendor_id', $companyId);
        }

        $paginator = $query->paginate($perPage);

        $mappedItems = collect($paginator->items())->map(function ($po) {
            $items = collect();

            if ($po->is_historical) {
                $items = $po->historicalItems->map(function ($item) {
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
                $items = $po->rfq->items->map(function ($item) {
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
                'items'             => $items,
            ];
        });

        return response()->json([
            'data'         => $mappedItems,
            'current_page' => $paginator->currentPage(),
            'last_page'    => $paginator->lastPage(),
            'total'        => $paginator->total(),
            'per_page'     => $paginator->perPage(),
        ]);
    }

    public function award(AwardVendorRequest $request, AwardVendorAction $action): JsonResponse
    {
        $rfq = Rfq::findOrFail($request->input('rfq_id'));
        $proposal = Proposal::findOrFail($request->input('proposal_id'));
        $manager = User::findOrFail($request->input('manager_id'));
        $po = $action->execute($manager, $rfq, $proposal);
        return response()->json(['po' => $po]);
    }
}
