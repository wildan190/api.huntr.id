<?php

use Illuminate\Support\Facades\Route;
use App\Domain\Proposal\Http\Controllers\ProposalController;

Route::prefix('api/proposals')->middleware('api')->group(function () {
    Route::post('', [ProposalController::class, 'store']);
});

Route::prefix('api/rfqs')->middleware('api')->group(function () {
    Route::get('{rfq}/rankings', [ProposalController::class, 'calculateRankings']);
});
