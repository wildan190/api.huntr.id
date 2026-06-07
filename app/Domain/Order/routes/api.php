<?php

use Illuminate\Support\Facades\Route;
use App\Domain\Order\Http\Controllers\OrderController;

Route::prefix('api/orders')->middleware(['api', 'auth:api'])->group(function () {
    Route::get('', [OrderController::class, 'index']);
    Route::post('award', [OrderController::class, 'award']);
    Route::post('{po}/confirm', [OrderController::class, 'confirm']);
    Route::get('{po}/print', [OrderController::class, 'printPo'])->withoutMiddleware('auth:api');
    
    // Negotiation routes (Legacy endpoints redirecting to new Domain)
    Route::get('negotiations', [\App\Domain\Negotiation\Http\Controllers\NegotiationController::class, 'index']);
    Route::post('negotiate', [\App\Domain\Negotiation\Http\Controllers\NegotiationController::class, 'store']);
    Route::post('negotiate/{negotiation}/respond', [\App\Domain\Negotiation\Http\Controllers\NegotiationController::class, 'respond']);
    Route::post('{po}/arrange-delivery', [OrderController::class, 'arrangeDelivery']);
});

Route::prefix('api/do')->middleware(['api'])->group(function () {
    Route::get('{deliveryOrder}/print', [OrderController::class, 'printDo'])->withoutMiddleware('auth:api');
});

Route::prefix('api/invoices')->middleware(['api', 'auth:api'])->group(function () {
    Route::get('{invoice}/print', [OrderController::class, 'printInvoice'])->withoutMiddleware('auth:api');
    Route::post('{invoice}/publish', [OrderController::class, 'publishInvoice']);
});
