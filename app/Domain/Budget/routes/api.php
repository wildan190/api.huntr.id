<?php

use App\Domain\Budget\Http\Controllers\BudgetController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/budgeting')->middleware(['api', 'auth:api'])->group(function () {
    Route::get('/apps', [BudgetController::class, 'apps']);
    Route::post('/apps/install', [BudgetController::class, 'install']);
    Route::delete('/apps/install', [BudgetController::class, 'uninstall']);
    Route::get('/dashboard', [BudgetController::class, 'dashboard']);
    Route::get('/cycles', [BudgetController::class, 'cycles']);
    Route::post('/cycles', [BudgetController::class, 'storeCycle']);
    Route::put('/cycles/{cycleId}', [BudgetController::class, 'updateCycle']);
    Route::post('/cycles/{cycleId}/activate', [BudgetController::class, 'activateCycle']);
    Route::get('/lines', [BudgetController::class, 'lines']);
    Route::post('/lines', [BudgetController::class, 'storeLine']);
    Route::put('/lines/{lineId}', [BudgetController::class, 'updateLine']);
    Route::get('/plans', [BudgetController::class, 'plans']);
    Route::post('/plans', [BudgetController::class, 'storePlan']);
    Route::put('/plans/{planId}', [BudgetController::class, 'updatePlan']);
    Route::post('/plans/{planId}/submit', [BudgetController::class, 'submitPlan']);
    Route::post('/plans/{planId}/approve', [BudgetController::class, 'approvePlan']);
    Route::post('/plans/{planId}/reject', [BudgetController::class, 'rejectPlan']);
    Route::post('/plans/{planId}/link-rfq', [BudgetController::class, 'linkRfq']);
    Route::post('/plans/{planId}/link-purchase-order', [BudgetController::class, 'linkPurchaseOrder']);
    Route::post('/plans/{planId}/actuals', [BudgetController::class, 'recordActual']);
});
