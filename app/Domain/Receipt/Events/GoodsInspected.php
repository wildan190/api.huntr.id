<?php

namespace App\Domain\Receipt\Events;

use App\Domain\Receipt\Models\GoodsReceipt;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class GoodsInspected
{
    use Dispatchable, SerializesModels;

    public function __construct(public GoodsReceipt $receipt)
    {
    }
}
