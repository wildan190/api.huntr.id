<?php

use Illuminate\Support\Facades\Route;
use App\Domain\Communication\Http\Controllers\NotificationController;
use App\Domain\Communication\Http\Controllers\CommunicationController;

Route::prefix('api/notifications')->middleware('api')->group(function () {
    Route::get('', [NotificationController::class, 'index']);
    Route::post('{id}/read', [NotificationController::class, 'markAsRead']);
    Route::post('read-all', [NotificationController::class, 'markAllAsRead']);
    Route::post('clear-all', [NotificationController::class, 'clearAll']);
});

Route::prefix('api/communication')->middleware('api')->group(function () {
    Route::post('whatsapp/refresh-token', [CommunicationController::class, 'refreshWhatsAppToken']);
});
