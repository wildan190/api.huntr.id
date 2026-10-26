<?php

use App\Domain\Receipt\Http\Controllers\ReceiptController;
use App\Domain\Receipt\Http\Controllers\GoodsReceiptController;
use Illuminate\Support\Facades\Route;

Route::prefix('api')->middleware(['api', 'auth:api'])->group(function () {
    // Goods Receipt endpoints
    Route::post('/receipts', [ReceiptController::class, 'store'])->name('receipts.store');
    
    // Goods Receipt Management
    Route::get('/receipts', [GoodsReceiptController::class, 'index']);
    Route::get('/receipts/{id}', [GoodsReceiptController::class, 'show']);
    Route::post('/receipts/{id}/inspect', [GoodsReceiptController::class, 'inspect']);
});


