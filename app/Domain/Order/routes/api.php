<?php

use Illuminate\Support\Facades\Route;
use App\Domain\Order\Http\Controllers\OrderController;

Route::prefix('api')->middleware('api')->group(function () {
    Route::get('orders', [OrderController::class, 'index']);
    Route::post('orders/award', [OrderController::class, 'award']);
});
