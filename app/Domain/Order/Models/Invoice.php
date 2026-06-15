<?php

namespace App\Domain\Order\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Invoice extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'purchase_order_id',
        'type', // proforma, final
        'amount',
        'status', // unpaid, paid, pending_finance
        'base_amount',
        'platform_fee',
        'midtrans_fee',
        'ppn_fee',
        'total_amount',
    ];

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }
}
