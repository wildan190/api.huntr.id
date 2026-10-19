<?php

use App\Domain\Subscription\Http\Controllers\SubscriptionController;
use App\Domain\Subscription\Http\Controllers\PricingAnnouncementController;
use App\Domain\Subscription\Http\Controllers\SubscriptionCheckoutController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/companies')->middleware(['api', 'cors', 'auth:api'])->group(function () {
    Route::get('{company}/subscription/pricing-announcement', [PricingAnnouncementController::class, 'show']);
    Route::post('{company}/subscription/pricing-announcement/{announcement}/acknowledge', [PricingAnnouncementController::class, 'acknowledge']);
    Route::get('{company}/subscription/access', [SubscriptionController::class, 'access']);
    Route::get('{company}/subscription', [SubscriptionController::class, 'show']);
    Route::post('{company}/subscription/checkouts', [SubscriptionCheckoutController::class, 'store']);
    Route::get('{company}/subscription/checkouts/current', [SubscriptionCheckoutController::class, 'current']);
    Route::get('{company}/subscription/checkouts/{checkout}', [SubscriptionCheckoutController::class, 'show']);
});

Route::get('api/subscription/plans', [SubscriptionController::class, 'plans'])
    ->middleware(['api', 'cors', 'auth:api']);
