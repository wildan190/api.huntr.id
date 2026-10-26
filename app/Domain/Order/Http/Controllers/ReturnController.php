<?php

namespace App\Domain\Order\Http\Controllers;

use App\Domain\Order\Actions\CreateReturnAction;
use App\Domain\Order\Models\GoodsReturn;
use App\Domain\Order\Models\PurchaseOrder;
use App\Domain\Company\Models\Company;
use App\Domain\Company\Services\CompanyAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ReturnController
 * 
 * Responsibility: Manage goods returns for rejected, defective, or damaged items.
 */
class ReturnController extends \App\Http\Controllers\Controller
{
    /**
     * Create a new return
     */
    public function store(Request $request, CreateReturnAction $action, CompanyAccessService $access): JsonResponse
    {
        $request->validate([
            'po_id' => 'required|uuid|exists:purchase_orders,id',
            'goods_receipt_id' => 'nullable|uuid|exists:goods_receipts,id',
            'bast_id' => 'nullable|uuid|exists:basts,id',
            'return_reason' => 'required|in:defective,damaged,incorrect_qty,incorrect_item,quality_issue,other',
            'items' => 'required|array|min:1',
            'items.*.rfq_item_id' => 'required|uuid',
            'items.*.quantity_returned' => 'required|integer|min:1',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.reason' => 'nullable|string',
            'return_description' => 'nullable|string',
            'photos' => 'nullable|array',
            'courier_name' => 'nullable|string|max:255',
            'tracking_number' => 'nullable|string|max:255',
        ]);

        try {
            $po = PurchaseOrder::findOrFail($request->input('po_id'));
            $this->assertCompanyAccess($request, $po->buyer_company_id, $po->vendor_id, $access);

            $return = $action->execute($po, $request->all());

            return response()->json([
                'message' => 'Return created successfully.',
                'return' => $return->load('purchaseOrder', 'buyerCompany', 'vendorCompany'),
            ], 201);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    /**
     * Get return by ID
     */
    public function show(Request $request, string $id, CompanyAccessService $access): JsonResponse
    {
        $return = GoodsReturn::with('purchaseOrder', 'buyerCompany', 'vendorCompany', 'inspectedByUser', 'approvedByUser')
            ->findOrFail($id);
        $this->assertCompanyAccess($request, $return->buyer_company_id, $return->vendor_company_id, $access);

        return response()->json(['return' => $return]);
    }

    /**
     * List returns for a company
     */
    public function index(Request $request, CompanyAccessService $access): JsonResponse
    {
        $companyId = $request->input('company_id');
        abort_unless($companyId, 422, 'company_id is required');
        $access->assertMember($request->user(), Company::query()->findOrFail($companyId));
        try {
            // Validate only required parameters without database checks first
            // Try to get data
            $query = GoodsReturn::where(function ($q) use ($companyId) {
                $q->where('buyer_company_id', $companyId)
                  ->orWhere('vendor_company_id', $companyId);
            });

            if ($request->has('po_id')) {
                $query->where('po_id', $request->input('po_id'));
            }

            if ($request->has('status')) {
                $query->where('status', $request->input('status'));
            }

            if ($request->has('return_reason')) {
                $query->where('return_reason', $request->input('return_reason'));
            }

            $perPage = $request->input('per_page', 10);
            $returns = $query->with('purchaseOrder', 'buyerCompany', 'vendorCompany')->orderBy('created_at', 'desc')->paginate($perPage);

            return response()->json($returns);
        } catch (\PDOException $e) {
            // Table doesn't exist yet
            \Log::warning('Returns table not found: ' . $e->getMessage());
            return response()->json([
                'data' => [],
                'current_page' => 1,
                'per_page' => 10,
                'total' => 0,
                'last_page' => 1,
                'message' => 'Returns feature is being initialized'
            ]);
        } catch (\Exception $e) {
            \Log::error('Return index error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'company_id' => $request->input('company_id')
            ]);
            return response()->json([
                'data' => [],
                'current_page' => 1,
                'per_page' => 10,
                'total' => 0,
                'last_page' => 1,
                'message' => 'Returns feature is being initialized'
            ]);
        }
    }

    /**
     * Update return status
     */
    public function updateStatus(Request $request, string $id, CompanyAccessService $access): JsonResponse
    {
        $request->validate([
            'status' => 'required|in:pending,in_transit,received,processed,cancelled',
            'notes' => 'nullable|string',
        ]);

        $return = GoodsReturn::findOrFail($id);
        $this->assertCompanyAccess($request, $return->buyer_company_id, $return->vendor_company_id, $access);

        $return->update([
            'status' => $request->input('status'),
        ]);

        if ($request->input('status') === 'received_at_vendor') {
            $return->update(['received_at_vendor' => now()]);
        }

        return response()->json([
            'message' => 'Return status updated successfully.',
            'return' => $return,
        ]);
    }

    /**
     * Inspect return (vendor side)
     */
    public function inspect(Request $request, string $id, CompanyAccessService $access): JsonResponse
    {
        $request->validate([
            'inspection_status' => 'required|in:approved,partial,rejected',
            'inspection_notes' => 'required|string',
        ]);

        $return = GoodsReturn::findOrFail($id);
        $vendor = Company::query()->findOrFail($return->vendor_company_id);
        $access->assertMember($request->user(), $vendor);

        if (!$return->canBeInspected()) {
            return response()->json(['message' => 'Return cannot be inspected at this status.'], 422);
        }

        $return->update([
            'inspection_status' => $request->input('inspection_status'),
            'inspection_notes' => $request->input('inspection_notes'),
            'inspected_by_user_id' => $request->user()->id,
            'inspected_at' => now(),
        ]);

        return response()->json([
            'message' => 'Inspection completed successfully.',
            'return' => $return,
        ]);
    }

    /**
     * Approve return
     */
    public function approve(Request $request, string $id, CompanyAccessService $access): JsonResponse
    {
        $request->validate([
            'approved_notes' => 'nullable|string',
        ]);

        $return = GoodsReturn::findOrFail($id);
        $buyer = Company::query()->findOrFail($return->buyer_company_id);
        $access->assertMember($request->user(), $buyer);

        if (!$return->canBeApproved()) {
            return response()->json(['message' => 'Return cannot be approved at this status.'], 422);
        }

        $return->update([
            'status' => 'processed',
            'approved_by_user_id' => $request->user()->id,
            'approved_at' => now(),
        ]);

        return response()->json([
            'message' => 'Return approved successfully.',
            'return' => $return,
        ]);
    }

    /**
     * Generate and display Return PDF in browser (for printing)
     */
    public function showPdf(Request $request, string $id, CompanyAccessService $access)
    {
        $return = GoodsReturn::with(['purchaseOrder', 'buyerCompany', 'vendorCompany', 'inspectedByUser', 'approvedByUser', 'bast'])
            ->findOrFail($id);
        $this->assertCompanyAccess($request, $return->buyer_company_id, $return->vendor_company_id, $access);

        $disk = config('filesystems.default');
        /** @var \Illuminate\Filesystem\FilesystemAdapter $storageDisk */
        $storageDisk = \Illuminate\Support\Facades\Storage::disk($disk);
        $buyerLogoUrl = null;
        if ($return->buyerCompany && $return->buyerCompany->logo_path) {
            $buyerLogoUrl = $this->getAssetUrl($storageDisk, $return->buyerCompany->logo_path);
        }
        $vendorLogoUrl = null;
        if ($return->vendorCompany && $return->vendorCompany->logo_path) {
            $vendorLogoUrl = $this->getAssetUrl($storageDisk, $return->vendorCompany->logo_path);
        }

        // Return view directly for browser display & printing
        return view('print.return', [
            'return' => $return,
            'buyer_logo_url' => $buyerLogoUrl,
            'vendor_logo_url' => $vendorLogoUrl,
        ]);
    }

    private function assertCompanyAccess(Request $request, ?string $buyerCompanyId, ?string $vendorCompanyId, CompanyAccessService $access): void
    {
        $buyer = $buyerCompanyId ? Company::query()->find($buyerCompanyId) : null;
        $vendor = $vendorCompanyId ? Company::query()->find($vendorCompanyId) : null;
        abort_unless(
            ($buyer && $access->isMember($request->user(), $buyer))
            || ($vendor && $access->isMember($request->user(), $vendor)),
            403,
            'Anda tidak memiliki akses ke dokumen ini.',
        );
    }

    /**
     * Helper to get asset URL with support for S3 temporary URLs
     */
    private function getAssetUrl(\Illuminate\Filesystem\FilesystemAdapter $storage, string $path): string
    {
        if (filter_var($path, FILTER_VALIDATE_URL)) {
            return $path;
        }

        $diskName = config('filesystems.default');
        if (in_array($diskName, ['s3', 'spaces', 'gcs', 'azure'])) {
            try {
                return $storage->temporaryUrl($path, now()->addHours(1));
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::warning('Failed to generate temporary URL, falling back to public URL', [
                    'error' => $e->getMessage(),
                    'path' => $path
                ]);
            }
        }

        return $storage->url($path);
    }
}

