<?php

use Illuminate\Support\Facades\Route;
use App\Domain\Company\Http\Controllers\CompanyController;

// Public: get invitation info by token (no auth needed — used by register page to pre-fill phone)
Route::get('api/invitations/info', [CompanyController::class, 'invitationInfo'])->middleware(['api', 'cors']);

Route::prefix('api/companies')->middleware(['api', 'cors', 'auth:sanctum'])->group(function () {
    Route::post('', [CompanyController::class, 'store']);
    Route::post('verify-npwp', [CompanyController::class, 'verifyNpwp']);
    Route::put('{company}', [CompanyController::class, 'update']);
    Route::get('my', [CompanyController::class, 'myCompanies']);
    Route::post('documents/upload', [CompanyController::class, 'uploadDocument']);
    Route::post('logo/upload', [CompanyController::class, 'uploadLogo']);
    Route::post('invite', [CompanyController::class, 'invite']);
    Route::post('accept-invitation', [CompanyController::class, 'acceptInvitation']);
    Route::get('{company}/members', [CompanyController::class, 'teamMembers']);
});
