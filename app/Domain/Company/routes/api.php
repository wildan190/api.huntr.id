<?php

use Illuminate\Support\Facades\Route;
use App\Domain\Company\Http\Controllers\CompanyController;

Route::prefix('api/companies')->middleware(['api', 'cors', 'auth:sanctum'])->group(function () {
    Route::post('', [CompanyController::class, 'store']);
    Route::post('verify-npwp', [CompanyController::class, 'verifyNpwp']);
    Route::put('{company}', [CompanyController::class, 'update']);
    Route::get('my', [CompanyController::class, 'myCompanies']);
    Route::post('documents/upload', [CompanyController::class, 'uploadDocument']);
    Route::post('logo/upload', [CompanyController::class, 'uploadLogo']);
});
