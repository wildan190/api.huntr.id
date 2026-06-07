<?php

namespace App\Domain\Order\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Domain\Receipt\Models\GoodsReceipt;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

class DeliveryOrder extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'purchase_order_id',
        'do_number',
        'tracking_number',
        'status', // shipped, delivered, received
    ];

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function goodsReceipts()
    {
        return $this->hasMany(GoodsReceipt::class, 'delivery_order_id');
    }
}
