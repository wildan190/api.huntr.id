<?php

namespace App\Domain\Order\Events;

use App\Domain\Order\Models\Bast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BastIssuedEvent
{
    use Dispatchable, SerializesModels;

    public Bast $bast;
    public string $buyerCompanyId;

    public function __construct(Bast $bast, string $buyerCompanyId)
    {
        $this->bast = $bast;
        $this->buyerCompanyId = $buyerCompanyId;
    }
}
