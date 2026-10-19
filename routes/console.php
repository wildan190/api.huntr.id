<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('seo:generate-sitemaps')->daily()->at('00:00');
Schedule::command('crowd-buy:evaluate-deadlines')->everyTenMinutes();
// Auto-aggregate historical PO demands on the 1st of every month at 01:00 WIB
// This generates/refreshes the automated CrowdBuy campaigns for the new month
Schedule::command('crowd-buy:aggregate-demands', ['--limit=30'])->monthlyOn(1, '01:00');
Schedule::call(fn () => app(\App\Domain\Subscription\Services\SubscriptionBillingEmailService::class)->enqueueOutstanding())
    ->everyFiveMinutes()->name('subscription-billing-email-retry')->withoutOverlapping();

