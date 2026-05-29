<?php

use Illuminate\Support\Facades\Route;
use App\Domain\Communication\Http\Controllers\NotificationController;

Route::prefix('api')->middleware('api')->group(function () {
    Route::get('notifications', [NotificationController::class, 'index']);
    Route::post('notifications/{id}/read', [NotificationController::class, 'markAsRead']);
    Route::post('notifications/read-all', [NotificationController::class, 'markAllAsRead']);
});
