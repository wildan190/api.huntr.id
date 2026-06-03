<?php

use Illuminate\Support\Facades\Route;
use App\Domain\Catalogue\Http\Controllers\CatalogueController;

Route::prefix('api/catalogues')->middleware(['api', 'cors'])->group(function () {
    // Public endpoints
    Route::get('', [CatalogueController::class, 'index']);
    Route::get('{catalogue}', [CatalogueController::class, 'show']);
    
    // Protected endpoints (require authentication)
    Route::middleware('auth:api')->group(function () {
        Route::post('', [CatalogueController::class, 'store']);
        Route::put('{catalogue}', [CatalogueController::class, 'update']);
        Route::post('import', [CatalogueController::class, 'import']);
    });
});

Route::prefix('api/orders')->middleware(['api', 'cors'])->group(function () {
    Route::get('historical', [CatalogueController::class, 'historicalPos']);
    Route::post('historical/import', [CatalogueController::class, 'importHistoricalPos']);
});
