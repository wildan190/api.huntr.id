<?php

use Illuminate\Support\Facades\Route;
use App\Domain\Admin\Http\Controllers\AdminController;
use App\Domain\Admin\Http\Controllers\AdminSettingController;

Route::post('api/admin/auth/login', [AdminController::class, 'login']);

Route::prefix('api/admin')->middleware(['api'])->group(function () {
    Route::get('admins', [AdminController::class, 'listAdmins']);
    Route::post('admins', [AdminController::class, 'createAdmin']);
    Route::get('users', [AdminController::class, 'listUsers']);

    // Settings / feature flags
    Route::get('settings', [AdminSettingController::class, 'index']);
    Route::post('settings', [AdminSettingController::class, 'update']);
});
