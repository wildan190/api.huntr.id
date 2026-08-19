<?php

use Illuminate\Support\Facades\Route;
use App\Domain\Catalogue\Http\Controllers\CatalogueController;

Route::prefix('api/catalogues')->middleware(['api', 'cors'])->group(function () {
    // Public endpoints
    Route::get('', [CatalogueController::class, 'index']);
    Route::get('{catalogue}', [CatalogueController::class, 'show']);
    Route::get('{catalogue}/seo', [\App\Domain\Catalogue\Http\Controllers\SeoController::class, 'show']);

    
    // Protected endpoints (require authentication)
    Route::middleware('auth:api')->group(function () {
        Route::post('', [CatalogueController::class, 'store']);
        Route::put('{catalogue}', [CatalogueController::class, 'update']);
        Route::delete('{catalogue}', [CatalogueController::class, 'destroy']);
        Route::post('import', [CatalogueController::class, 'import']);
    });
});

Route::prefix('api/orders')->middleware(['api', 'cors'])->group(function () {
    Route::get('historical', [CatalogueController::class, 'historicalPos']);
    Route::post('historical/import', [CatalogueController::class, 'importHistoricalPos']);
});

Route::prefix('api/admin/catalogues')->middleware(['api'])->group(function () {
    Route::get('', [\App\Domain\Catalogue\Http\Controllers\AdminCatalogueController::class, 'index']);
    Route::post('', [\App\Domain\Catalogue\Http\Controllers\AdminCatalogueController::class, 'store']);
    Route::post('{catalogue}', [\App\Domain\Catalogue\Http\Controllers\AdminCatalogueController::class, 'update']);
    Route::match(['put', 'patch'], '{catalogue}', [\App\Domain\Catalogue\Http\Controllers\AdminCatalogueController::class, 'update']);
    Route::delete('{catalogue}', [\App\Domain\Catalogue\Http\Controllers\AdminCatalogueController::class, 'destroy']);
});

// Analytics: trending search keywords (public, cached)
Route::prefix('api/analytics')->middleware(['api', 'cors'])->group(function () {
    Route::get('trending-searches', [\App\Domain\Catalogue\Http\Controllers\TrendingSearchController::class, 'index']);
});

