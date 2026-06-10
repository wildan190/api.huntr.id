<?php

namespace App\Domain\Receipt\Http\Controllers;

use App\Domain\Receipt\Models\GoodsReceipt;
use App\Domain\Receipt\Actions\InspectGoodsReceiptAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class GoodsReceiptController
{
    public function index(Request $request): JsonResponse
    {
        $companyId = $request->input('company_id');
        $poId = $request->input('po_id');
        
        $query = GoodsReceipt::with([
            'deliveryOrder.purchaseOrder',
            'inspectedBy',
            'returns'
        ]);

        // Filter by company through delivery order -> purchase order
        if ($companyId) {
            $query->whereHas('deliveryOrder.purchaseOrder', function ($q) use ($companyId) {
                $q->where('buyer_company_id', $companyId)
                  ->orWhere('vendor_company_id', $companyId);
            });
        }

        // Filter by PO
        if ($poId) {
            $query->whereHas('deliveryOrder.purchaseOrder', function ($q) use ($poId) {
                $q->where('id', $poId);
            });
        }

        $receipts = $query->orderBy('created_at', 'desc')->paginate(20);

        return response()->json($receipts);
    }

    public function show(string $id): JsonResponse
    {
        $receipt = GoodsReceipt::with([
            'deliveryOrder.purchaseOrder.buyerCompany',
            'deliveryOrder.purchaseOrder.vendorCompany',
            'inspectedBy',
            'returns.resolutionProposedBy',
            'returns.resolutionApprovedBy'
        ])->findOrFail($id);

        return response()->json($receipt);
    }

    public function inspect(Request $request, string $id, InspectGoodsReceiptAction $action): JsonResponse
    {
        $receipt = GoodsReceipt::findOrFail($id);

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
}
