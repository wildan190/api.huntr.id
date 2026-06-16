<?php

use Illuminate\Support\Facades\Route;
use App\Domain\AI\Http\Controllers\AiController;

/**
 * AI Domain Routes
 *
 * Semua endpoint AI — tidak memerlukan auth agar buyer bisa search
 * tanpa login, tapi rank-proposals dan generate-pr memerlukan auth.
 */
Route::prefix('api/ai')->middleware(['api', 'cors'])->group(function () {

    // Public: AI search katalog (buyer maupun guest bisa search)
    Route::post('search', [AiController::class, 'search']);

    // Public: AI product comparison (by product IDs from DB)
    Route::post('compare', [AiController::class, 'compare']);

    // Public: AI comparison text from natural language query (external knowledge)
    Route::post('compare-text', [AiController::class, 'compareText']);

    // Protected: memerlukan auth (akses data internal)
    Route::middleware('auth:api')->group(function () {
        Route::post('rank-proposals', [AiController::class, 'rankProposals']);
        Route::post('generate-pr',    [AiController::class, 'generatePr']);
    });
});
