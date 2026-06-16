<?php

namespace App\Domain\Order\Events;

use App\Domain\Order\Models\Bast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BastCompletedEvent
{
    use Dispatchable, SerializesModels;

    public Bast $bast;

    public function __construct(Bast $bast)
    {
        $this->bast = $bast;
    }
}
