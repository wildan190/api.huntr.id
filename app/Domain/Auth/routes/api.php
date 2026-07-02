<?php

use App\Domain\Auth\Http\Controllers\AdminController;
use App\Domain\Auth\Http\Controllers\AdminCatalogueController;
use App\Domain\Auth\Http\Controllers\AdminTransactionController;
use App\Domain\Auth\Http\Controllers\AccountController;
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

Route::post('api/admin/auth/login', [AdminController::class, 'login']);

Route::prefix('api/admin')->middleware(['api'])->group(function () {
    Route::get('companies', [AdminController::class, 'listCompanies']);
    Route::post('companies/{company}/audit', [AdminController::class, 'auditCompany']);
    Route::get('catalogues', [AdminCatalogueController::class, 'index']);
    Route::post('catalogues', [AdminCatalogueController::class, 'store']);
    Route::post('catalogues/{catalogue}', [AdminCatalogueController::class, 'update']);
    Route::match(['put', 'patch'], 'catalogues/{catalogue}', [AdminCatalogueController::class, 'update']);
    Route::delete('catalogues/{catalogue}', [AdminCatalogueController::class, 'destroy']);
    Route::get('transactions', [AdminTransactionController::class, 'index']);
    Route::get('transactions/escrow-summary', [AdminTransactionController::class, 'escrowSummary']);
});

Route::prefix('api/account')->middleware(['api', 'auth:api'])->group(function () {
    Route::put('password', [AccountController::class, 'updatePassword']);
    Route::put('whatsapp', [AccountController::class, 'updateWhatsapp']);
    Route::get('sessions', [AccountController::class, 'getSessions']);
    Route::delete('sessions/{sessionId}', [AccountController::class, 'logoutSession']);
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
