<?php

use Illuminate\Support\Facades\Route;
use App\Domain\Auth\Http\Controllers\AuthController;

Route::prefix('api/auth')->middleware('api')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
    Route::post('otp/send', [AuthController::class, 'sendOtp']);
    Route::post('otp/verify', [AuthController::class, 'verifyOtp']);
});

Route::prefix('api/admin')->middleware('api')->group(function () {
    Route::post('auth/login', [\App\Domain\Auth\Http\Controllers\AdminController::class, 'login']);
    Route::get('companies', [\App\Domain\Auth\Http\Controllers\AdminController::class, 'listCompanies']);
    Route::post('companies/{id}/audit', [\App\Domain\Auth\Http\Controllers\AdminController::class, 'auditCompany']);
});
