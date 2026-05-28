<?php

use Illuminate\Support\Facades\Route;
use App\Domain\Proposal\Http\Controllers\ProposalController;

Route::prefix('api')->middleware('api')->group(function () {
    Route::post('proposals', [ProposalController::class, 'store']);
    Route::get('rfqs/{rfq}/rankings', [ProposalController::class, 'calculateRankings']);
});
