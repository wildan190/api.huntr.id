<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;
use App\Domain\Auth\Http\Controllers\RoleSwitchController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:api');

// Role switch route (local only)
Route::post('/role-switch', [RoleSwitchController::class, 'switch'])
    ->middleware(['api', 'auth:api']);

// Broadcast routes using API guard
Broadcast::routes(['middleware' => ['auth:api']]);
