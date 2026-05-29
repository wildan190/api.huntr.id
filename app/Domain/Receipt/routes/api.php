<?php

use Illuminate\Support\Facades\Route;
use App\Domain\Receipt\Http\Controllers\ReceiptController;

Route::prefix('api')->middleware('api')->group(function () {
    Route::post('receipts', [ReceiptController::class, 'store']);
});
