<?php

use Illuminate\Support\Facades\Route;
use App\Domain\Rfq\Http\Controllers\RfqController;

Route::prefix('api/rfqs')->middleware(['api', 'auth:api'])->group(function () {
    Route::get('', [RfqController::class, 'index']);
    Route::get('{rfq}', [RfqController::class, 'show']);
    Route::get('{rfq}/rankings', [RfqController::class, 'rankings']);
    Route::post('', [RfqController::class, 'store']);
    Route::post('{rfq}/invite-vendor', [RfqController::class, 'inviteVendor']);

    Route::middleware('manager.only')->group(function () {
        Route::post('{rfq}/approve', [RfqController::class, 'approve']);
        Route::post('{rfq}/reject', [RfqController::class, 'reject']);
    });
});
