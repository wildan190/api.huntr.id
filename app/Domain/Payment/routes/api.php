<?php

use App\Domain\Payment\Http\Controllers\PaymentController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/payments')->group(function () {
    Route::get('', [PaymentController::class, 'index'])->middleware('auth:api');
    Route::post('', [PaymentController::class, 'store'])->middleware('auth:api');
    Route::get('{payment}', [PaymentController::class, 'show'])->middleware('auth:api');
    Route::post('webhook', [PaymentController::class, 'webhook']);
});
