<?php

use App\Domain\CrowdBuy\Http\Controllers\AdminCampaignController;
use App\Domain\CrowdBuy\Http\Controllers\BuyerCampaignController;
use App\Domain\CrowdBuy\Http\Controllers\BuyerOrderController;
use App\Domain\CrowdBuy\Http\Controllers\CrowdBuyPaymentController;
use App\Domain\CrowdBuy\Http\Controllers\PublicCampaignController;
use App\Domain\CrowdBuy\Http\Controllers\SupplierCampaignController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/crowd-buy')->middleware('api')->group(function () {
    Route::get('campaigns', [PublicCampaignController::class, 'index']);
    Route::get('campaigns/categories', [PublicCampaignController::class, 'categories']);
    Route::get('campaigns/{idOrCode}', [PublicCampaignController::class, 'show']);
    Route::get('invite/{inviteCode}', [BuyerCampaignController::class, 'resolveInvite']);

    Route::get('suppliers', [BuyerCampaignController::class, 'suppliers']);

    Route::post('payments/webhook', [CrowdBuyPaymentController::class, 'webhook']);
});

Route::prefix('api/crowd-buy')->middleware(['api', 'auth:api'])->group(function () {
    Route::get('initiatives', [BuyerCampaignController::class, 'index']);
    Route::post('initiatives', [BuyerCampaignController::class, 'store']);
    Route::post('initiatives/{campaignId}/accept-bid/{bidId}', [BuyerCampaignController::class, 'acceptBid']);

    Route::get('orders', [BuyerOrderController::class, 'index']);
    Route::get('orders/{orderId}', [BuyerOrderController::class, 'show']);
    Route::post('campaigns/{campaignId}/orders', [BuyerOrderController::class, 'store']);
    Route::post('orders/{orderId}/cancel', [BuyerOrderController::class, 'cancel']);

    Route::post('orders/{orderId}/pay', [CrowdBuyPaymentController::class, 'pay']);
    Route::post('orders/{orderId}/simulate-pay', [CrowdBuyPaymentController::class, 'simulateSuccess']);
});

Route::prefix('api/crowd-buy/supplier')->middleware(['api', 'auth:api'])->group(function () {
    Route::get('campaigns', [SupplierCampaignController::class, 'index']);
    Route::post('campaigns', [SupplierCampaignController::class, 'store']);
    Route::get('campaigns/{campaignId}', [SupplierCampaignController::class, 'show']);
    Route::match(['put', 'patch'], 'campaigns/{campaignId}', [SupplierCampaignController::class, 'update']);
    Route::post('campaigns/{campaignId}/submit', [SupplierCampaignController::class, 'submit']);

    Route::get('collective-rfqs', [SupplierCampaignController::class, 'collectiveRfqs']);
    Route::post('collective-rfqs/{campaignId}/bids', [SupplierCampaignController::class, 'submitBid']);

    Route::get('orders', [SupplierCampaignController::class, 'orders']);
    Route::post('orders/{orderId}/fulfill', [SupplierCampaignController::class, 'fulfillOrder']);
});

Route::prefix('api/admin/crowd-buy')->middleware(['api', 'auth:api'])->group(function () {
    Route::get('campaigns', [AdminCampaignController::class, 'index']);
    Route::get('dashboard', [AdminCampaignController::class, 'dashboard']);
    Route::post('campaigns/{campaignId}/review', [AdminCampaignController::class, 'review']);
    Route::post('campaigns/{campaignId}/suspend', [AdminCampaignController::class, 'suspend']);
    Route::post('evaluate', [AdminCampaignController::class, 'evaluate']);
});
