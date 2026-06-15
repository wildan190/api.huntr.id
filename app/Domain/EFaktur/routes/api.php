<?php

use Illuminate\Support\Facades\Route;
use App\Domain\EFaktur\Http\Controllers\EFakturController;

Route::prefix('api/efaktur')->middleware(['api', 'auth:sanctum'])->group(function () {
    Route::post('', [EFakturController::class, 'store']);
    Route::get('', [EFakturController::class, 'index']);
    Route::get('{id}', [EFakturController::class, 'show']);
    Route::get('{id}/pdf', [EFakturController::class, 'pdf']);
    Route::post('{id}/cancel', [EFakturController::class, 'cancel']);
});
