<?php

use Illuminate\Support\Facades\Route;
use App\Domain\Proposal\Http\Controllers\ProposalController;

Route::prefix('api/proposals')->middleware(['api', 'auth:api'])->group(function () {
    Route::get('', [ProposalController::class, 'index']);
    Route::post('', [ProposalController::class, 'store']);
    Route::get('my-rank', [ProposalController::class, 'vendorRankings']);
    Route::get('{proposal}', [ProposalController::class, 'show']);
    
    // Manager-only routes for proposal approval and awarding
    Route::middleware('manager.only')->group(function () {
        Route::get('manager/awaiting-approval', [ProposalController::class, 'awaitingApproval']);
        Route::post('{proposal}/award', [ProposalController::class, 'awardWinner']);
        Route::post('{proposal}/approve', [ProposalController::class, 'approveWinner']);
    });
});

Route::prefix('api/rfqs')->middleware(['api', 'auth:api'])->group(function () {
    Route::get('{rfq}/rankings', [ProposalController::class, 'calculateRankings']);
});
