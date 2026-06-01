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
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'company_id'     => 'nullable|exists:companies,id',
            'item_code'      => 'required|string|max:255',
            'name'           => 'required|string|max:255',
            'category'       => 'nullable|string|max:255',
            'specifications' => 'nullable|string',
            'uom'            => 'required|string|max:50',
            'price'          => 'required|numeric|min:0',
        ]);

        $user = $request->user();

        // Check if user is admin
        $isAdmin = $user && get_class($user) === 'App\Domain\Auth\Models\Admin';
        
        if ($isAdmin) {
            // Admin can create catalogues without a specific company
            // Use a special admin company ID or create one if needed
            $companyId = $request->input('company_id');
            if (!$companyId) {
                // Create or get admin company
                $adminCompany = Company::firstOrCreate(
                    ['name' => 'Admin Marketplace', 'type' => 'vendor'],
                    ['status' => 'approved']
                );
                $companyId = $adminCompany->id;
            }
        } else {
            // Non-admin users must provide a company_id
            $request->validate(['company_id' => 'required|exists:companies,id']);
            $companyId = $request->input('company_id');
            $company = Company::findOrFail($companyId);
            
            // Check if user is vendor (has company with vendor type)
            $isVendor = false;
            if ($user && get_class($user) === 'App\Domain\Auth\Models\User') {
                $userCompanies = $user->companies()->where('type', 'vendor')->get();
                $isVendor = $userCompanies->contains('id', $company->id);
            }

            if (!$isVendor) {
                return response()->json(['message' => 'Hanya Vendor yang dapat menambahkan katalog ke perusahaan ini.'], 422);
            }
        }

        $item = \App\Domain\Catalogue\Models\Catalogue::create([
            'company_id' => $companyId,
            'item_code' => $request->input('item_code'),
            'name' => $request->input('name'),
            'category' => $request->input('category'),
            'specifications' => $request->input('specifications'),
            'uom' => $request->input('uom'),
            'price' => $request->input('price'),
        ]);

        return response()->json([
            'message' => 'Produk berhasil ditambahkan ke katalog.',
            'data'    => $item
        ], 201);
    }

    public function import(ImportHistoricalDataRequest $request): JsonResponse
    {
        $company = Company::findOrFail($request->input('company_id'));

        if ($company->type !== 'vendor') {
            return response()->json([
                'message' => 'Hanya perusahaan bertipe Vendor yang dapat mengimpor data katalog.',
            ], 422);
        }

        // Store uploaded file in storage/app/private/imports directory
        $path = $request->file('csv')->store('imports', 'local');

        // Dispatch sync import job to be processed via queue (Horizon)
        ImportCatalogueJob::dispatch($company->id, $path);

        return response()->json([
            'message' => 'Data katalog sedang diimpor ke dalam antrean.',
            'queued'  => true,
            'type'    => $company->type,
        ], 200);
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
        $path = $request->file('csv')->store('imports', 'local');

        // Dispatch historical PO import job to be processed via queue (Horizon)
        \App\Domain\Order\Jobs\ImportHistoricalPoJob::dispatch($company->id, $path);

        return response()->json([
            'message' => 'Data Purchase Order sedang diimpor ke dalam antrean.',
            'queued'  => true,
            'type'    => $company->type,
        ], 200);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $request->validate([
            'company_id'     => 'required|exists:companies,id',
            'item_code'      => 'required|string|max:255',
            'name'           => 'required|string|max:255',
            'category'       => 'nullable|string|max:255',
            'specifications' => 'nullable|string',
            'uom'            => 'required|string|max:50',
            'price'          => 'required|numeric|min:0',
        ]);

        $item = \App\Domain\Catalogue\Models\Catalogue::findOrFail($id);
        $company = Company::findOrFail($request->input('company_id'));

        if ($company->type !== 'vendor') {
            return response()->json(['message' => 'Hanya Vendor yang dapat mengubah katalog.'], 422);
        }

        $item->update($request->all());

        return response()->json([
            'message' => 'Produk katalog berhasil diperbarui.',
            'data'    => $item,
        ], 200);
    }

    public function show($id): JsonResponse
    {
        $item = \App\Domain\Catalogue\Models\Catalogue::with('company')->findOrFail($id);

        return response()->json([
            'data' => $item,
        ], 200);
    }


    /**
     * GET /api/catalogues
     * Returns catalogue items. If company_id is provided, filters by that company.
     * Supports search and category filtering for marketplace.
     * Shows catalogues from vendor companies and admin-created catalogues.
     */
    public function index(Request $request): JsonResponse
    {
        $query = \App\Domain\Catalogue\Models\Catalogue::query()->with('company');

        if ($request->has('company_id')) {
            $query->where('company_id', $request->input('company_id'));
        } else {
            // For global marketplace, show catalogues from vendors and admin-created catalogues
            $query->whereHas('company', function ($q) {
                // Show catalogues from vendor companies (approved or pending)
                $q->where(function ($subQ) {
                    $subQ->where('type', 'vendor')
                         ->whereIn('status', ['approved', 'pending']);
                });
            });
        }

        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('item_code', 'ilike', "%{$search}%")
                  ->orWhere('category', 'ilike', "%{$search}%");
            });
        }

        if ($request->has('category')) {
            $query->where('category', $request->input('category'));
        }

        $catalogues = $query->orderBy('id', 'desc')->paginate($request->input('per_page', 20));

        return response()->json($catalogues);
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
