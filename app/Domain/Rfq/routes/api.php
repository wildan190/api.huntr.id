<?php

use Illuminate\Support\Facades\Route;
use App\Domain\Rfq\Http\Controllers\RfqController;

Route::prefix('api/rfqs')->middleware('api')->group(function () {
    Route::get('', [RfqController::class, 'index']);
    Route::get('{rfq}', [RfqController::class, 'show']);
    Route::post('', [RfqController::class, 'store']);
    Route::post('{rfq}/approve', [RfqController::class, 'approve']);
});
