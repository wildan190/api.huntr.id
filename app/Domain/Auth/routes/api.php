<?php

use App\Domain\Auth\Http\Controllers\AccountController;
use App\Domain\Auth\Http\Controllers\TwoFactorController;
use Illuminate\Support\Facades\Route;
use App\Domain\Auth\Http\Controllers\AuthController;
use App\Domain\Auth\Http\Controllers\RoleSwitchController;
use Illuminate\Support\Facades\DB;

Route::prefix('api/auth')->middleware(['api'])->group(function () {
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:5,1');
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:5,1');

    Route::post('otp/send', [AuthController::class, 'sendOtp'])->middleware('throttle:3,1');
    Route::post('otp/verify', [AuthController::class, 'verifyOtp'])->middleware('throttle:5,1');
    Route::post('password/reset-whatsapp', [AuthController::class, 'resetPassword'])->middleware('throttle:3,1');

    Route::post('two-factor/verify', [AuthController::class, 'verifyTwoFactor'])->middleware('throttle:5,1');

    Route::middleware('auth:api')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
    });
});

Route::prefix('api/account')->middleware(['api', 'auth:api'])->group(function () {
    Route::put('password', [AccountController::class, 'updatePassword'])->middleware('throttle:5,1');
    Route::put('whatsapp', [AccountController::class, 'updateWhatsapp'])->middleware('throttle:5,1');
    Route::get('sessions', [AccountController::class, 'getSessions']);
    Route::delete('sessions/{sessionId}', [AccountController::class, 'logoutSession']);

    // User profile
    Route::get('profile', [AccountController::class, 'getProfile']);
    Route::post('profile', [AccountController::class, 'updateProfile'])->middleware('throttle:10,1');

    // 2FA routes (CSRF-free, uses Bearer token auth)
    Route::prefix('two-factor-authentication')->group(function () {
        Route::post('/', [TwoFactorController::class, 'enable']);
        Route::delete('/', [TwoFactorController::class, 'disable']);
        Route::post('/confirm', [TwoFactorController::class, 'confirm'])->middleware('throttle:5,1');
    });
    Route::get('two-factor-qr-code', [TwoFactorController::class, 'qrCode']);
    Route::get('two-factor-recovery-codes', [TwoFactorController::class, 'recoveryCodes']);
    Route::post('two-factor-recovery-codes', [TwoFactorController::class, 'regenerateRecoveryCodes'])->middleware('throttle:5,1');
});

Route::middleware(['api', 'auth:api'])->group(function () {
    Route::post('/role-switch', [RoleSwitchController::class, 'switch'])->middleware('throttle:10,1');

    Route::get('/debug-roles', function (\Illuminate\Http\Request $request) {
        $user = $request->user();

        $user->unsetRelation('roles');
        $user->load('roles');

        $rolesFromDb = \App\Domain\Access\Models\Role::whereHas('users', function ($q) use ($user) {
            $q->where('model_id', $user->id);
        })->get();

        return response()->json([
            'user_id' => $user->id,
            'user_name' => $user->name,
            'user_email' => $user->email,
            'roles_from_relation' => $user->roles->map(fn($r) => [
                'id' => $r->id,
                'name' => $r->name,
                'slug' => $r->slug,
            ]),
            'roles_from_db' => $rolesFromDb->map(fn($r) => [
                'id' => $r->id,
                'name' => $r->name,
                'slug' => $r->slug,
            ]),
            'model_roles_table' => DB::table('model_roles')
                ->where('model_id', $user->id)
                ->where('model_type', get_class($user))
                ->get(),
        ]);
    });
});