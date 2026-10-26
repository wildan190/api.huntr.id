<?php

namespace App\Domain\Receipt\Http\Controllers;

use App\Domain\Receipt\Models\GoodsReceipt;
use App\Domain\Receipt\Actions\InspectGoodsReceiptAction;
use App\Domain\Company\Models\Company;
use App\Domain\Company\Services\CompanyAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class GoodsReceiptController
{
    public function index(Request $request, CompanyAccessService $access): JsonResponse
    {
        $companyId = $request->input('company_id');
        $poId = $request->input('po_id');
        abort_unless($companyId, 422, 'Company ID is required.');

        $company = Company::query()->findOrFail($companyId);
        $access->assertMember($request->user(), $company);
        
        $query = GoodsReceipt::with([
            'deliveryOrder.purchaseOrder',
            'inspectedBy',
            'returns'
        ]);

        $query->whereHas('deliveryOrder.purchaseOrder', function ($q) use ($companyId) {
            $q->where('buyer_company_id', $companyId)
                ->orWhere('vendor_id', $companyId);
        });

        // Filter by PO
        if ($poId) {
            $query->whereHas('deliveryOrder.purchaseOrder', function ($q) use ($poId) {
                $q->where('id', $poId);
            });
        }

        $receipts = $query->orderBy('created_at', 'desc')->paginate(20);

        return response()->json($receipts);
    }

    public function show(Request $request, string $id, CompanyAccessService $access): JsonResponse
    {
        $receipt = GoodsReceipt::with([
            'deliveryOrder.purchaseOrder.buyerCompany',
            'deliveryOrder.purchaseOrder.vendorCompany',
            'inspectedBy',
            'returns.resolutionProposedBy',
            'returns.resolutionApprovedBy'
        ])->findOrFail($id);

        $this->assertReceiptAccess($request, $receipt, $access);

        return response()->json($receipt);
    }

    public function inspect(Request $request, string $id, InspectGoodsReceiptAction $action, CompanyAccessService $access): JsonResponse
    {
        $receipt = GoodsReceipt::findOrFail($id);
        $this->assertReceiptAccess($request, $receipt, $access);

        // Validate
        $validator = Validator::make($request->all(), [
            'items_inspection' => 'required|array|min:1',
            'items_inspection.*.inventory_code' => 'required|string',
            'items_inspection.*.inventory_name' => 'required|string',
            'items_inspection.*.ordered_qty' => 'required|numeric|min:0',
            'items_inspection.*.delivered_qty' => 'required|numeric|min:0',
            'items_inspection.*.accepted_qty' => 'required|numeric|min:0',
            'items_inspection.*.rejected_qty' => 'required|numeric|min:0',
            'items_inspection.*.rejection_reason' => 'nullable|string',
            'items_inspection.*.condition_notes' => 'nullable|string',
            'items_inspection.*.unit_price' => 'required|numeric|min:0',
            'accepted_items' => 'nullable|array',
            'rejected_items' => 'nullable|array',
            'inspection_notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // Check if already inspected
        if ($receipt->inspection_status === 'completed') {
            return response()->json([
                'message' => 'This goods receipt has already been inspected'
            ], 400);
        }

        try {
            $updatedReceipt = $action->execute($receipt, $request->all());

            return response()->json([
                'message' => 'Goods receipt inspected successfully',
                'receipt' => $updatedReceipt->load(['deliveryOrder', 'returns']),
                'return_created' => $updatedReceipt->hasRejectedItems()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to inspect goods receipt',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    private function assertReceiptAccess(Request $request, GoodsReceipt $receipt, CompanyAccessService $access): void
    {
        $receipt->loadMissing('deliveryOrder.purchaseOrder');
        $purchaseOrder = $receipt->deliveryOrder?->purchaseOrder;
        abort_unless($purchaseOrder, 404, 'Purchase order not found for this receipt.');

        $buyer = Company::query()->findOrFail($purchaseOrder->buyer_company_id);
        $vendor = $purchaseOrder->vendor_id ? Company::query()->find($purchaseOrder->vendor_id) : null;
        abort_unless(
            $access->isMember($request->user(), $buyer)
            || ($vendor && $access->isMember($request->user(), $vendor)),
            403,
            'Anda tidak memiliki akses ke Goods Receipt ini.',
        );
    }
}
