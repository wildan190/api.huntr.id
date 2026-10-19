<?php

use Illuminate\Support\Facades\Route;
use App\Domain\Admin\Http\Controllers\AdminController;

Route::post('api/admin/auth/login', [AdminController::class, 'login']);

Route::prefix('api/admin')->middleware(['api', 'admin.session'])->group(function () {
    Route::get('admins', [AdminController::class, 'listAdmins']);
    Route::post('admins', [AdminController::class, 'createAdmin']);
    Route::get('users', [AdminController::class, 'listUsers']);
    Route::delete('users/{userId}', [AdminController::class, 'deleteUser']);
    Route::post('users/{userId}/change-password', [AdminController::class, 'changeUserPassword']);
    Route::get('enterprise/transactions', [\App\Domain\Subscription\Http\Controllers\AdminEnterpriseController::class, 'transactions']);
    Route::post('enterprise/transactions/{checkout}/refresh', [\App\Domain\Subscription\Http\Controllers\AdminEnterpriseController::class, 'refresh']);
    Route::get('enterprise/pricing', [\App\Domain\Subscription\Http\Controllers\AdminEnterpriseController::class, 'pricing']);
    Route::post('enterprise/pricing', [\App\Domain\Subscription\Http\Controllers\AdminEnterpriseController::class, 'updatePricing']);
});
