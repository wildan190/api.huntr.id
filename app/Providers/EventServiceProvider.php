<?php

namespace App\Providers;

use App\Domain\EFaktur\Listeners\CreateEfakturOnBastCompleted;
use App\Domain\Order\Events\BastCompletedEvent;
use App\Domain\Order\Events\BastIssuedEvent;
use App\Domain\Order\Listeners\SendBastIssuedNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event to listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        BastIssuedEvent::class => [
            SendBastIssuedNotification::class,
        ],
        BastCompletedEvent::class => [
            CreateEfakturOnBastCompleted::class,
        ],
        \App\Domain\Receipt\Events\GoodsInspected::class => [
            \App\Domain\Receipt\Listeners\SendGoodsInspectedNotification::class,
        ],
        \App\Domain\Order\Events\ReturnCreated::class => [
            \App\Domain\Order\Listeners\SendReturnCreatedNotification::class,
        ],
        \App\Domain\Order\Events\ResolutionProposed::class => [
            \App\Domain\Order\Listeners\SendResolutionProposedNotification::class,
        ],
        \App\Domain\Order\Events\ResolutionApproved::class => [
            \App\Domain\Order\Listeners\SendResolutionApprovedNotification::class,
        ],
        \App\Domain\Order\Events\ResolutionRejected::class => [
            \App\Domain\Order\Listeners\SendResolutionRejectedNotification::class,
        ],
    ];

    /**
     * Register any events for your application.
     */
    public function boot(): void
    {
        //
    }
}
