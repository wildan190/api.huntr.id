<?php

use App\Domain\Receipt\Http\Controllers\ReceiptController;
use App\Domain\Receipt\Http\Controllers\GoodsReceiptController;
use Illuminate\Support\Facades\Route;

// Old receipts endpoint with api middleware (for backward compatibility)
Route::prefix('api/receipts')->middleware('api')->group(function () {
    Route::post('', [ReceiptController::class, 'store']);
});

Route::prefix('api')->middleware('auth:sanctum')->group(function () {
    // Goods Receipt endpoints
    Route::post('/receipts', [ReceiptController::class, 'store'])->name('receipts.store');
    
    // Goods Receipt Management
    Route::get('/receipts', [GoodsReceiptController::class, 'index']);
    Route::get('/receipts/{id}', [GoodsReceiptController::class, 'show']);
    Route::post('/receipts/{id}/inspect', [GoodsReceiptController::class, 'inspect']);
});


