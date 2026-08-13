<?php

use Illuminate\Support\Facades\Route;
use App\Domain\Admin\Http\Controllers\AdminController;

Route::post('api/admin/auth/login', [AdminController::class, 'login']);

Route::prefix('api/admin')->middleware(['api'])->group(function () {
    Route::get('admins', [AdminController::class, 'listAdmins']);
    Route::post('admins', [AdminController::class, 'createAdmin']);
    Route::get('users', [AdminController::class, 'listUsers']);
    Route::delete('users/{userId}', [AdminController::class, 'deleteUser']);
});
