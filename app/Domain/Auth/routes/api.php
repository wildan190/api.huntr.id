<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use App\Domain\Auth\Http\Controllers\AuthController;
use App\Domain\Auth\Http\Controllers\AccountController;

Route::prefix('api/auth')->middleware(['api', 'cors'])->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
    Route::post('logout', function (Request $request) {
        auth()->logout();
        return response()->json(['message' => 'Logged out successfully']);
    });
    Route::post('otp/send', [AuthController::class, 'sendOtp']);
    Route::post('otp/verify', [AuthController::class, 'verifyOtp']);
});

Route::prefix('api/account')->middleware(['api', 'auth:api', 'cors'])->group(function () {
    Route::put('password', [AccountController::class, 'updatePassword']);
    Route::put('whatsapp', [AccountController::class, 'updateWhatsapp']);
    Route::get('sessions', [AccountController::class, 'getSessions']);
    Route::delete('sessions/{id}', [AccountController::class, 'logoutSession']);
});

Route::middleware(['api', 'auth:api', 'cors'])->group(function () {
    Route::get('api/user', function (Request $request) {
        return $request->user();
    });
});

Route::prefix('api/admin')->middleware(['api', 'cors'])->group(function () {
    Route::post('auth/login', [\App\Domain\Auth\Http\Controllers\AdminController::class, 'login']);
    Route::get('companies', [\App\Domain\Auth\Http\Controllers\AdminController::class, 'listCompanies']);
    Route::post('companies/{id}/audit', [\App\Domain\Auth\Http\Controllers\AdminController::class, 'auditCompany']);
    
    // Transactions & Escrow
    Route::get('transactions', [\App\Domain\Auth\Http\Controllers\AdminTransactionController::class, 'index']);
    Route::get('transactions/escrow-summary', [\App\Domain\Auth\Http\Controllers\AdminTransactionController::class, 'escrowSummary']);
    
    // Global Catalogue
    Route::get('catalogues', [\App\Domain\Auth\Http\Controllers\AdminCatalogueController::class, 'index']);
    Route::post('catalogues', [\App\Domain\Auth\Http\Controllers\AdminCatalogueController::class, 'store']);
});
