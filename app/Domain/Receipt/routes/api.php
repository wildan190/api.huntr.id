<?php

use Illuminate\Support\Facades\Route;
use App\Domain\Receipt\Http\Controllers\ReceiptController;

Route::prefix('api/receipts')->middleware('api')->group(function () {
    Route::post('', [ReceiptController::class, 'store']);
});
