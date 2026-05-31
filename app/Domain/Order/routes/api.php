<?php

use Illuminate\Support\Facades\Route;
use App\Domain\Order\Http\Controllers\OrderController;

Route::prefix('api/orders')->middleware('api')->group(function () {
    Route::get('', [OrderController::class, 'index']);
    Route::post('award', [OrderController::class, 'award']);
});
