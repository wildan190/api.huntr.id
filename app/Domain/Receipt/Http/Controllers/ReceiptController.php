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

        $do = $po->deliveryOrders()->where('status', 'delivered')->first();
        if (!$do) {
            return response()->json(['message' => 'No delivered Delivery Order found for this PO.'], 422);
        }

        $receipt = $action->execute($do, $request->validated());
        return response()->json(['receipt' => $receipt]);
    }
}
