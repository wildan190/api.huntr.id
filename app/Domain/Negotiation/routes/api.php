<?php

use App\Domain\Negotiation\Http\Controllers\NegotiationController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/negotiations')->middleware(['api', 'auth:api'])->group(function () {
    Route::post('', [NegotiationController::class, 'store']);
    Route::post('{negotiation}/respond', [NegotiationController::class, 'respond']);
    Route::get('proposal/{proposal}', [NegotiationController::class, 'showByProposal']);
});
