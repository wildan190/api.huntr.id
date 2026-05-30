<?php

use Illuminate\Support\Facades\Route;
use App\Domain\Auth\Http\Controllers\AuthController;
use App\Domain\Auth\Http\Controllers\AccountController;

Route::prefix('api/auth')->middleware('api')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
    Route::post('otp/send', [AuthController::class, 'sendOtp']);
    Route::post('otp/verify', [AuthController::class, 'verifyOtp']);
});

Route::prefix('api/account')->middleware(['api', 'auth'])->group(function () {
    Route::put('password', [AccountController::class, 'updatePassword']);
    Route::put('whatsapp', [AccountController::class, 'updateWhatsapp']);
    Route::get('sessions', [AccountController::class, 'getSessions']);
    Route::delete('sessions/{id}', [AccountController::class, 'logoutSession']);
});

Route::prefix('api/admin')->middleware('api')->group(function () {
    Route::post('auth/login', [\App\Domain\Auth\Http\Controllers\AdminController::class, 'login']);
    Route::get('companies', [\App\Domain\Auth\Http\Controllers\AdminController::class, 'listCompanies']);
    Route::post('companies/{id}/audit', [\App\Domain\Auth\Http\Controllers\AdminController::class, 'auditCompany']);
});
