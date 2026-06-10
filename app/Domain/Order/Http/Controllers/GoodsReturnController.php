<?php

namespace App\Domain\Order\Http\Controllers;

use App\Domain\Order\Models\GoodsReturn;
use App\Domain\Order\Actions\ProposeReturnResolutionAction;
use App\Domain\Order\Actions\ApproveReturnResolutionAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class GoodsReturnController
{
    public function index(Request $request): JsonResponse
    {
        $companyId = $request->input('company_id');
        $status = $request->input('status');
        $resolutionStatus = $request->input('resolution_status');
        
        $query = GoodsReturn::with([
            'purchaseOrder',
            'goodsReceipt',
            'buyerCompany',
            'vendorCompany',
            'resolutionProposedBy',
            'resolutionApprovedBy',
            'debitNote'
        ]);

        // Filter by company
        if ($companyId) {
            $query->where(function ($q) use ($companyId) {
                $q->where('buyer_company_id', $companyId)
                  ->orWhere('vendor_company_id', $companyId);
            });
        }

        if ($status) {
            $query->where('status', $status);
        }

        if ($resolutionStatus) {
            $query->where('resolution_status', $resolutionStatus);
        }

        $returns = $query->orderBy('created_at', 'desc')->paginate(20);

        return response()->json($returns);
    }

    public function show(string $id): JsonResponse
    {
        $return = GoodsReturn::with([
            'purchaseOrder.buyerCompany',
            'purchaseOrder.vendorCompany',
            'goodsReceipt.deliveryOrder',
            'buyerCompany',
            'vendorCompany',
            'resolutionProposedBy',
            'resolutionApprovedBy',
            'createdBy',
            'debitNote'
        ])->findOrFail($id);

        return response()->json($return);
    }

    public function proposeResolution(
        Request $request, 
        string $id, 
        ProposeReturnResolutionAction $action
    ): JsonResponse {
        $return = GoodsReturn::findOrFail($id);

        // Validate
        $validator = Validator::make($request->all(), [
            'resolution_type' => 'required|in:replacement,refund,partial_refund,credit_note',
            'resolution_details' => 'nullable|array',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // Check status
        if ($return->resolution_status !== 'pending_vendor' && $return->resolution_status !== 'rejected') {
            return response()->json([
                'message' => 'Cannot propose resolution. Current status: ' . $return->resolution_status
            ], 400);
        }

        try {
            $updatedReturn = $action->execute($return, $request->all());

            return response()->json([
                'message' => 'Resolution proposed successfully',
                'return' => $updatedReturn
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to propose resolution',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function approveResolution(
        Request $request, 
        string $id, 
        ApproveReturnResolutionAction $action
    ): JsonResponse {
        $return = GoodsReturn::findOrFail($id);

        // Validate
        $validator = Validator::make($request->all(), [
            'approved' => 'required|boolean',
            'buyer_notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // Check status
        if ($return->resolution_status !== 'proposed') {
            return response()->json([
                'message' => 'Cannot approve/reject. Current status: ' . $return->resolution_status
            ], 400);
        }

        try {
            $approved = $request->boolean('approved');
            $updatedReturn = $action->execute($return, $approved, $request->input('buyer_notes'));

            return response()->json([
                'message' => $approved ? 'Resolution approved successfully' : 'Resolution rejected',
                'return' => $updatedReturn->load(['debitNote'])
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to process resolution',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function complete(string $id): JsonResponse
    {
        $return = GoodsReturn::findOrFail($id);

        // Check status
        if ($return->resolution_status !== 'buyer_approved') {
            return response()->json([
                'message' => 'Cannot complete. Resolution must be approved first.'
            ], 400);
        }

        try {
            $return->update([
                'status' => 'completed',
                'resolution_status' => 'completed'
            ]);

            return response()->json([
                'message' => 'Return marked as completed',
                'return' => $return->fresh()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to complete return',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
