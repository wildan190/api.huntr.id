<?php

namespace App\Domain\Wms\Http\Controllers;

use App\Domain\Wms\Http\Requests\AdjustStockRequest;
use App\Domain\Wms\Http\Requests\AllocateStockRequest;
use App\Domain\Wms\Http\Requests\CheckStockAvailabilityRequest;
use App\Domain\Wms\Http\Requests\ImportGoodsReceiptsRequest;
use App\Domain\Wms\Http\Requests\PackWarehouseOrderRequest;
use App\Domain\Wms\Http\Requests\PutAwayStockRequest;
use App\Domain\Wms\Http\Requests\ReceiveStockRequest;
use App\Domain\Wms\Http\Requests\SetReorderLevelRequest;
use App\Domain\Wms\Http\Requests\ShipWarehouseOrderRequest;
use App\Domain\Wms\Http\Requests\StoreWarehouseBinRequest;
use App\Domain\Wms\Http\Requests\StoreWarehouseRequest;
use App\Domain\Wms\Http\Requests\TransferStockRequest;
use App\Domain\Wms\Http\Requests\UpdateWarehouseOrderStatusRequest;
use App\Domain\Wms\Http\Requests\UpdateWarehouseRequest;
use App\Domain\Wms\Services\WmsAnalyticsService;
use App\Domain\Wms\Services\WmsAppService;
use App\Domain\Wms\Services\WmsGoodsReceiptImportService;
use App\Domain\Wms\Services\WmsInventoryService;
use App\Domain\Wms\Services\WmsOrderService;
use Illuminate\Http\Request;

class WmsController
{
    public function apps(Request $request, WmsAppService $service)
    {
        return $service->apps($request);
    }

    public function install(Request $request, WmsAppService $service)
    {
        return $service->install($request);
    }

    public function uninstall(Request $request, WmsAppService $service)
    {
        return $service->uninstall($request);
    }

    public function dashboard(Request $request, WmsAnalyticsService $service)
    {
        return $service->dashboard($request);
    }

    public function report(Request $request, WmsAnalyticsService $service)
    {
        return $service->report($request);
    }

    public function warehouses(Request $request, WmsInventoryService $service)
    {
        return $service->warehouses($request);
    }

    public function storeWarehouse(StoreWarehouseRequest $request, WmsInventoryService $service)
    {
        return $service->storeWarehouse($request);
    }

    public function updateWarehouse(UpdateWarehouseRequest $request, string $warehouseId, WmsInventoryService $service)
    {
        return $service->updateWarehouse($request, $warehouseId);
    }

    public function bins(Request $request, string $warehouseId, WmsInventoryService $service)
    {
        return $service->bins($request, $warehouseId);
    }

    public function storeBin(StoreWarehouseBinRequest $request, WmsInventoryService $service)
    {
        return $service->storeBin($request);
    }

    public function catalogue(Request $request, WmsInventoryService $service)
    {
        return $service->catalogue($request);
    }

    public function inboundOrders(Request $request, WmsInventoryService $service)
    {
        return $service->inboundOrders($request);
    }

    public function stock(Request $request, WmsInventoryService $service)
    {
        return $service->stock($request);
    }

    public function setReorderLevel(SetReorderLevelRequest $request, int $stockId, WmsInventoryService $service)
    {
        return $service->setReorderLevel($request, $stockId);
    }

    public function receive(ReceiveStockRequest $request, WmsInventoryService $service)
    {
        return $service->receive($request);
    }

    public function receipts(Request $request, WmsInventoryService $service)
    {
        return $service->receipts($request);
    }

    public function importGoodsReceipts(ImportGoodsReceiptsRequest $request, WmsGoodsReceiptImportService $service)
    {
        return $service->import($request);
    }

    public function repairLegacyGoodsReceiptStock(Request $request, WmsGoodsReceiptImportService $service)
    {
        return $service->repairLegacyInventory($request);
    }

    public function putaway(PutAwayStockRequest $request, WmsInventoryService $service)
    {
        return $service->putaway($request);
    }

    public function adjust(AdjustStockRequest $request, WmsInventoryService $service)
    {
        return $service->adjust($request);
    }

    public function transfer(TransferStockRequest $request, WmsInventoryService $service)
    {
        return $service->transfer($request);
    }

    public function allocate(AllocateStockRequest $request, WmsOrderService $service)
    {
        return $service->allocate($request);
    }

    public function availability(CheckStockAvailabilityRequest $request, WmsOrderService $service)
    {
        return $service->availability($request);
    }

    public function pack(PackWarehouseOrderRequest $request, int $id, WmsOrderService $service)
    {
        return $service->pack($request, $id);
    }

    public function ship(ShipWarehouseOrderRequest $request, int $id, WmsOrderService $service)
    {
        return $service->ship($request, $id);
    }

    public function startPicking(UpdateWarehouseOrderStatusRequest $request, int $id, WmsOrderService $service)
    {
        return $service->startPicking($request, $id);
    }

    public function release(UpdateWarehouseOrderStatusRequest $request, int $id, WmsOrderService $service)
    {
        return $service->release($request, $id);
    }

    public function orders(Request $request, WmsOrderService $service)
    {
        return $service->orders($request);
    }
}
