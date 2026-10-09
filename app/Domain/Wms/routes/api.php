<?php

use App\Domain\Wms\Http\Controllers\WmsController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/wms')->middleware(['api', 'auth:api'])->group(function () {
    Route::get('/apps', [WmsController::class, 'apps']);
    Route::post('/apps/install', [WmsController::class, 'install']);
    Route::delete('/apps/install', [WmsController::class, 'uninstall']);
    Route::get('/dashboard', [WmsController::class, 'dashboard']);
    Route::get('/warehouses', [WmsController::class, 'warehouses']);
    Route::post('/warehouses', [WmsController::class, 'storeWarehouse']);
    Route::put('/warehouses/{warehouseId}', [WmsController::class, 'updateWarehouse']);
    Route::get('/warehouses/{warehouseId}/bins', [WmsController::class, 'bins']);
    Route::post('/warehouses/{warehouseId}/bins', [WmsController::class, 'storeBin']);
    Route::get('/stock', [WmsController::class, 'stock']);
    Route::put('/stock/{stockId}/reorder-level', [WmsController::class, 'setReorderLevel']);
    Route::get('/catalogue', [WmsController::class, 'catalogue']);
    Route::get('/inbound-orders', [WmsController::class, 'inboundOrders']);
    Route::post('/receiving', [WmsController::class, 'receive']);
    Route::get('/receipts', [WmsController::class, 'receipts']);
    Route::post('/goods-receipts/import', [WmsController::class, 'importGoodsReceipts']);
    Route::post('/goods-receipts/repair-legacy', [WmsController::class, 'repairLegacyGoodsReceiptStock']);
    Route::post('/putaway', [WmsController::class, 'putaway']);
    Route::post('/adjustments', [WmsController::class, 'adjust']);
    Route::post('/transfers', [WmsController::class, 'transfer']);
    Route::post('/allocations', [WmsController::class, 'allocate']);
    Route::post('/stock/availability', [WmsController::class, 'availability']);
    Route::get('/orders', [WmsController::class, 'orders']);
    Route::post('/orders/{id}/pack', [WmsController::class, 'pack']);
    Route::post('/orders/{id}/ship', [WmsController::class, 'ship']);
    Route::post('/orders/{id}/start-picking', [WmsController::class, 'startPicking']);
    Route::post('/orders/{id}/release', [WmsController::class, 'release']);
    Route::get('/reports', [WmsController::class, 'report']);
});
