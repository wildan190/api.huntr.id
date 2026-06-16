<?php

use App\Domain\Auth\Http\Controllers\AdminController;
use Illuminate\Support\Facades\Route;
use App\Domain\Auth\Http\Controllers\AuthController;
use App\Domain\Auth\Http\Controllers\RoleSwitchController;
use Illuminate\Support\Facades\DB;

Route::prefix('api/auth')->middleware(['api'])->group(function () {
    Route::post('login', [AuthController::class, 'login']);
    Route::post('register', [AuthController::class, 'register']);
    Route::post('otp/send', [AuthController::class, 'sendOtp']);
    Route::post('otp/verify', [AuthController::class, 'verifyOtp']);

    Route::middleware('auth:api')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
    });
});

//admin auth login
Route::post('api/admin/auth/login', [AdminController::class, 'login']);

Route::prefix('api/admin')->middleware(['api'])->group(function () {
    Route::get('companies', [AdminController::class, 'companies']);
    Route::get('catalogues', [AdminController::class, 'catalogues']);
    Route::get('transactions', [AdminController::class, 'transactions']);
    Route::get('transactions/escrow-summary', [AdminController::class, 'escrowSummary']);
});

Route::middleware(['api', 'auth:api'])->group(function () {
    Route::post('/role-switch', [RoleSwitchController::class, 'switch']);

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
            'roles_from_relation' => $user->roles->map(fn ($r) => [
                'id' => $r->id,
                'name' => $r->name,
                'slug' => $r->slug,
            ]),
            'roles_from_db' => $rolesFromDb->map(fn ($r) => [
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
