<?php

namespace App\Domain\Receipt\Http\Controllers;

use Illuminate\Http\Request;
use App\Domain\Receipt\Actions\CreateGoodsReceiptAction;
use App\Domain\Receipt\Http\Requests\CreateGoodsReceiptRequest;
use Illuminate\Http\JsonResponse;
use App\Domain\Order\Models\PurchaseOrder;

class ReceiptController extends \App\Http\Controllers\Controller
{
    public function store(CreateGoodsReceiptRequest $request, CreateGoodsReceiptAction $action): JsonResponse
    {
        $po = PurchaseOrder::findOrFail($request->input('po_id'));
        $companyId = $request->input('company_id');

        // Data Isolation: Ensure the PO belongs to the company submitting the receipt
        if ($po->buyer_company_id !== $companyId) {
            return response()->json(['message' => 'This Purchase Order does not belong to your company.'], 403);
        }

        // Date restriction validation
        if ($po->expected_receiving_date && !env('APP_DEBUG_GR_DATE', false)) {
            $expectedDate = \Carbon\Carbon::parse($po->expected_receiving_date)->startOfDay();
            if (now()->startOfDay()->lessThan($expectedDate)) {
                return response()->json([
                    'message' => 'You cannot receive goods before the expected receiving date: ' . $expectedDate->format('Y-m-d')
                ], 422);
            }
        }

        $do = $po->deliveryOrders()->whereIn('status', ['shipped', 'delivered'])->first();
        if (!$do) {
            return response()->json(['message' => 'No shipped or delivered Delivery Order found for this PO.'], 422);
        }

        $receipt = $action->execute($do, $request->validated());
        return response()->json(['receipt' => $receipt]);
    }
}
