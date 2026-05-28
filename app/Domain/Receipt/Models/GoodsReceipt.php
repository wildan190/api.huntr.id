<?php

namespace App\Domain\Receipt\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Domain\Order\Models\DeliveryOrder;

class GoodsReceipt extends Model
{
    use HasFactory;

    protected $fillable = [
        'delivery_order_id',
        'received_qty',
        'handover_document_path',
        'status', // completed
    ];

    public function deliveryOrder()
    {
        return $this->belongsTo(DeliveryOrder::class, 'delivery_order_id');
    }
}
