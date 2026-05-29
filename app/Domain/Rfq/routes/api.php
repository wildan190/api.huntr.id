<?php

use Illuminate\Support\Facades\Route;
use App\Domain\Rfq\Http\Controllers\RfqController;

Route::prefix('api')->middleware('api')->group(function () {
    Route::get('rfqs', [RfqController::class, 'index']);
    Route::get('rfqs/{rfq}', [RfqController::class, 'show']);
    Route::post('rfqs', [RfqController::class, 'store']);
    Route::post('rfqs/{rfq}/approve', [RfqController::class, 'approve']);
});
