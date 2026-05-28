<?php

namespace App\Domain\Receipt\Http\Controllers;

use App\Domain\Receipt\Actions\CreateGoodsReceiptAction;
use App\Domain\Receipt\Http\Requests\CreateGoodsReceiptRequest;
use Illuminate\Http\JsonResponse;
use App\Domain\Order\Models\PurchaseOrder;

class ReceiptController extends \App\Http\Controllers\Controller
{
    public function store(CreateGoodsReceiptRequest $request, CreateGoodsReceiptAction $action): JsonResponse
    {
        $po = PurchaseOrder::findOrFail($request->input('po_id'));
        $do = $po->deliveryOrders()->firstOrFail();
        $receipt = $action->execute($do, $request->validated());
        return response()->json(['receipt' => $receipt]);
    }
}
