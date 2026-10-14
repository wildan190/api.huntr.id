<?php

use App\Domain\CrowdBuy\Http\Controllers\AdminCampaignController;
use App\Domain\CrowdBuy\Http\Controllers\BuyerOrderController;
use App\Domain\CrowdBuy\Http\Controllers\CrowdBuyPaymentController;
use App\Domain\CrowdBuy\Http\Controllers\PublicCampaignController;
use App\Domain\CrowdBuy\Http\Controllers\SupplierCampaignController;
use Illuminate\Support\Facades\Route;

// 1. Public Discovery
Route::prefix('api/crowd-buy')->middleware('api')->group(function () {
    Route::get('campaigns', [PublicCampaignController::class, 'index']);
    Route::get('campaigns/categories', [PublicCampaignController::class, 'categories']);
    Route::get('campaigns/{idOrCode}', [PublicCampaignController::class, 'show']);

    // Payment Webhook (Publicly reachable for Midtrans)
    Route::post('payments/webhook', [CrowdBuyPaymentController::class, 'webhook']);
});

// 2. Buyer Authenticated Endpoints
Route::prefix('api/crowd-buy')->middleware(['api', 'auth:api'])->group(function () {
    // Orders
    Route::get('orders', [BuyerOrderController::class, 'index']);
    Route::get('orders/{orderId}', [BuyerOrderController::class, 'show']);
    Route::post('campaigns/{campaignId}/orders', [BuyerOrderController::class, 'store']);
    Route::post('orders/{orderId}/cancel', [BuyerOrderController::class, 'cancel']);

    // Payments
    Route::post('orders/{orderId}/pay', [CrowdBuyPaymentController::class, 'pay']);
    Route::post('orders/{orderId}/simulate-pay', [CrowdBuyPaymentController::class, 'simulateSuccess']);
});

// 3. Supplier Authenticated Endpoints
Route::prefix('api/crowd-buy/supplier')->middleware(['api', 'auth:api'])->group(function () {
    Route::get('campaigns', [SupplierCampaignController::class, 'index']);
    Route::post('campaigns', [SupplierCampaignController::class, 'store']);
    Route::get('campaigns/{campaignId}', [SupplierCampaignController::class, 'show']);
    Route::match(['put', 'patch'], 'campaigns/{campaignId}', [SupplierCampaignController::class, 'update']);
    Route::post('campaigns/{campaignId}/submit', [SupplierCampaignController::class, 'submit']);

    Route::get('orders', [SupplierCampaignController::class, 'orders']);
    Route::post('orders/{orderId}/fulfill', [SupplierCampaignController::class, 'fulfillOrder']);
});

// 4. Admin Authenticated Endpoints (Supports both auth:api and admin.session)
Route::prefix('api/admin/crowd-buy')->middleware(['api', 'auth:api'])->group(function () {
    Route::get('campaigns', [AdminCampaignController::class, 'index']);
    Route::get('dashboard', [AdminCampaignController::class, 'dashboard']);
    Route::post('campaigns/{campaignId}/review', [AdminCampaignController::class, 'review']);
    Route::post('campaigns/{campaignId}/suspend', [AdminCampaignController::class, 'suspend']);
    Route::post('evaluate', [AdminCampaignController::class, 'evaluate']);
});
