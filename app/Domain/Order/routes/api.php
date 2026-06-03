<?php

use Illuminate\Support\Facades\Route;
use App\Domain\Order\Http\Controllers\OrderController;

Route::prefix('api/orders')->middleware(['api', 'auth:api'])->group(function () {
    Route::get('', [OrderController::class, 'index']);
    Route::post('award', [OrderController::class, 'award']);
    Route::post('{po}/confirm', [OrderController::class, 'confirm']);
    Route::get('{po}/print', [OrderController::class, 'printPo'])->withoutMiddleware('auth:api');
});

Route::prefix('api/invoices')->middleware(['api'])->group(function () {
    Route::get('{invoice}/print', [OrderController::class, 'printInvoice']);
});
