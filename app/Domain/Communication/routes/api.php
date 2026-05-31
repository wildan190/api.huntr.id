<?php

use Illuminate\Support\Facades\Route;
use App\Domain\Communication\Http\Controllers\NotificationController;
use App\Domain\Communication\Http\Controllers\CommunicationController;

Route::prefix('api')->middleware('api')->group(function () {
    Route::get('notifications', [NotificationController::class, 'index']);
    Route::post('notifications/{id}/read', [NotificationController::class, 'markAsRead']);
    Route::post('notifications/read-all', [NotificationController::class, 'markAllAsRead']);
    
    // WhatsApp token refresh
    Route::post('communication/whatsapp/refresh-token', [CommunicationController::class, 'refreshWhatsAppToken']);
});
