<?php

namespace App\Domain\Catalogue\Http\Controllers;

use App\Domain\Catalogue\Http\Requests\ImportHistoricalDataRequest;
use App\Domain\Catalogue\Jobs\ImportCatalogueJob;
use App\Domain\Company\Models\Company;
use App\Domain\Order\Models\PurchaseOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CatalogueController extends \App\Http\Controllers\Controller
{
    public function import(ImportHistoricalDataRequest $request): JsonResponse
    {
        $company = Company::findOrFail($request->input('company_id'));

        if ($company->type !== 'vendor') {
            return response()->json([
                'message' => 'Hanya perusahaan bertipe Vendor yang dapat mengimpor data katalog.',
            ], 422);
        }

        // Store uploaded file in storage/app/private/imports directory
        $path = $request->file('csv')->store('imports');

        // Dispatch queued import job to be processed by Laravel Horizon/Redis
        ImportCatalogueJob::dispatch($company->id, $path);

        return response()->json([
            'message' => 'Unggah data katalog sedang diproses di antrean.',
            'queued'  => true,
            'type'    => $company->type,
        ], 202);
    }

    public function importHistoricalPos(ImportHistoricalDataRequest $request): JsonResponse
    {
        $company = Company::findOrFail($request->input('company_id'));

        if ($company->type !== 'buyer') {
            return response()->json([
                'message' => 'Hanya perusahaan bertipe Buyer yang dapat mengimpor data Purchase Order.',
            ], 422);
        }

        // Store uploaded file in storage/app/private/imports directory
        $path = $request->file('csv')->store('imports');

        // Dispatch queued historical PO import job
        \App\Domain\Order\Jobs\ImportHistoricalPoJob::dispatch($company->id, $path);

        return response()->json([
            'message' => 'Unggah data Purchase Order sedang diproses di antrean.',
            'queued'  => true,
            'type'    => $company->type,
        ], 202);
    }


    /**
     * GET /api/catalogues?company_id=X
     * Returns catalogue items for a vendor company.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'company_id' => 'required|exists:companies,id',
        ]);

        $company    = Company::findOrFail($request->input('company_id'));
        $catalogues = \App\Domain\Catalogue\Models\Catalogue::where('company_id', $company->id)
            ->orderBy('id', 'desc')
            ->get();

        return response()->json(['data' => $catalogues]);
    }

    /**
     * GET /api/orders/historical?company_id=X
     * Returns historical PO headers + line items for a buyer company.
     */
    public function historicalPos(Request $request): JsonResponse
    {
        $request->validate([
            'company_id' => 'required|exists:companies,id',
        ]);

        $companyId = $request->input('company_id');

        $pos = PurchaseOrder::with('historicalItems')
            ->where('buyer_company_id', $companyId)
            ->where('is_historical', true)
            ->orderBy('id', 'desc')
            ->get()
            ->map(function ($po) {
                return [
                    'id'               => $po->id,
                    'po_number'        => $po->po_number,
                    'vendor_name'      => $po->vendor_name,
                    'department'       => $po->department,
                    'currency'         => $po->currency,
                    'purchase_category' => $po->purchase_category,
                    'purchase_type'    => $po->purchase_type,
                    'order_date'       => $po->order_date?->format('Y-m-d'),
                    'status'           => $po->status,
                    'created_by'       => $po->created_by,
                    'approved_by'      => $po->approved_by,
                    'items'            => $po->historicalItems->map(function ($item) {
                        return [
                            'id'                     => $item->id,
                            'pr_reference_number'    => $item->pr_reference_number,
                            'inventory_code'         => $item->inventory_code,
                            'inventory_name'         => $item->inventory_name,
                            'category'               => $item->category,
                            'specifications'         => $item->specifications,
                            'uom'                    => $item->uom,
                            'qty'                    => $item->qty,
                            'unit_price'             => $item->unit_price,
                            'amount'                 => $item->amount,
                            'tax_amount'             => $item->tax_amount,
                            'total_amount'           => $item->total_amount,
                            'currency'               => $item->currency,
                            'exchange_rate'          => $item->exchange_rate,
                            'clerk'                  => $item->clerk,
                            'created_by'             => $item->created_by,
                            'approved_by'            => $item->approved_by,
                            'order_date'             => $item->order_date?->format('Y-m-d'),
                            'expected_receiving_date' => $item->expected_receiving_date?->format('Y-m-d'),
                        ];
                    }),
                ];
            });

        return response()->json([
            'data'  => $pos,
            'total' => $pos->count(),
        ]);
    }
}
