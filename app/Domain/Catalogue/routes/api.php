<?php

use Illuminate\Support\Facades\Route;
use App\Domain\Catalogue\Http\Controllers\CatalogueController;

Route::prefix('api')->middleware(['api', 'cors'])->group(function () {
    Route::get('catalogues', [CatalogueController::class, 'index']);
    Route::get('catalogues/{id}', [CatalogueController::class, 'show']);
    Route::post('catalogues', [CatalogueController::class, 'store']);
    Route::put('catalogues/{id}', [CatalogueController::class, 'update']);
    Route::post('catalogues/import', [CatalogueController::class, 'import']);
    Route::get('orders/historical', [CatalogueController::class, 'historicalPos']);
    Route::post('orders/historical/import', [CatalogueController::class, 'importHistoricalPos']);
});
