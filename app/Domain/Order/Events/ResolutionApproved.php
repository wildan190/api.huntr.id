<?php

namespace App\Domain\Order\Events;

use App\Domain\Order\Models\GoodsReturn;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ResolutionApproved
{
    use Dispatchable, SerializesModels;

    public function __construct(public GoodsReturn $return)
    {
    }
}
