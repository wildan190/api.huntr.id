<?php

use App\Domain\Subscription\Http\Controllers\SubscriptionController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/companies')->middleware(['api', 'cors', 'auth:api'])->group(function () {
    Route::get('{company}/subscription', [SubscriptionController::class, 'show']);
});
