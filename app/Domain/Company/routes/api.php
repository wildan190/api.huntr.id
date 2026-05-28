<?php

use Illuminate\Support\Facades\Route;
use App\Domain\Company\Http\Controllers\CompanyController;

Route::prefix('api')->middleware('api')->group(function () {
    Route::post('companies', [CompanyController::class, 'store']);
    Route::put('companies/{company}', [CompanyController::class, 'update']);
    Route::get('companies/my', [CompanyController::class, 'myCompanies']);
    Route::post('companies/documents/upload', [CompanyController::class, 'uploadDocument']);
    Route::post('companies/logo/upload', [CompanyController::class, 'uploadLogo']);
});
