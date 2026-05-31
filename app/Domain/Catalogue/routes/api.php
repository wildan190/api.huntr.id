<?php

use Illuminate\Support\Facades\Route;
use App\Domain\Catalogue\Http\Controllers\CatalogueController;

Route::prefix('api/catalogues')->middleware(['api', 'cors'])->group(function () {
    Route::get('', [CatalogueController::class, 'index']);
    Route::get('{id}', [CatalogueController::class, 'show']);
    Route::post('', [CatalogueController::class, 'store']);
    Route::put('{id}', [CatalogueController::class, 'update']);
    Route::post('import', [CatalogueController::class, 'import']);
});

Route::prefix('api/orders')->middleware(['api', 'cors'])->group(function () {
    Route::get('historical', [CatalogueController::class, 'historicalPos']);
    Route::post('historical/import', [CatalogueController::class, 'importHistoricalPos']);
});
