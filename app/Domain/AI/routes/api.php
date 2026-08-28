<?php

use Illuminate\Support\Facades\Route;
use App\Domain\AI\Http\Controllers\AiController;

/**
 * AI Domain Routes
 *
 * Endpoint AI & Agentic Procurement di platform Huntr.
 */
Route::prefix('api/ai')->middleware(['api', 'cors'])->group(function () {

    // Agentic Procurement Endpoints
    Route::post('agentic-procurement/run', [AiController::class, 'agenticRun']);
    Route::post('agentic-procurement/chat', [AiController::class, 'agenticChat']);
    Route::post('agentic-procurement/create-pr', [AiController::class, 'agenticCreatePr'])->middleware('auth:api');

    // Public: AI search katalog (buyer maupun guest bisa search)
    Route::post('search', [AiController::class, 'search']);

    // Public: AI product comparison (by product IDs from DB)
    Route::post('compare', [AiController::class, 'compare']);

    // Public: AI comparison text from natural language query (external knowledge)
    Route::post('compare-text', [AiController::class, 'compareText']);

    // Public / Vendor: Auto-fill metadata produk menggunakan ChatGPT
    Route::post('autofill-catalogue', [AiController::class, 'autofillCatalogue']);

    // Public / Vendor: Generate gambar produk menggunakan AI (DALL-E)
    Route::post('generate-image', [AiController::class, 'generateProductImage']);

    // Protected: memerlukan auth (akses data internal)
    Route::middleware('auth:api')->group(function () {
        Route::post('rank-proposals', [AiController::class, 'rankProposals']);
        Route::post('generate-pr',    [AiController::class, 'generatePr']);
        Route::get('usage',           [AiController::class, 'getUsage']);
    });
});

// Demo Mode AI Vendor Bots Routes
Route::prefix('api/demo')->middleware(['api', 'cors'])->group(function () {
    Route::get('bots', [\App\Domain\AI\Http\Controllers\DemoBotController::class, 'getBotRoster']);
    Route::post('rfq/{rfq}/generate-bots', [\App\Domain\AI\Http\Controllers\DemoBotController::class, 'generateBotsForRfq']);
    Route::post('negotiation/{negotiation}/respond', [\App\Domain\AI\Http\Controllers\DemoBotController::class, 'respondNegotiation']);
    Route::post('po/{po}/confirm', [\App\Domain\AI\Http\Controllers\DemoBotController::class, 'confirmPo']);
});
