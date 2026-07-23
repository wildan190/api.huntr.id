<?php

namespace App\Domain\Order\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Domain\Receipt\Models\GoodsReceipt;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

/**
 * @property string $id
 * @property string $purchase_order_id
 * @property string $do_number
 * @property string|null $tracking_number
 * @property string|null $delivery_address
 * @property string $status
 * @property string|null $handed_by_user_id
 * @property string|null $handed_by_name
 * @property string|null $handed_by_position
 * @property \Illuminate\Support\Carbon|null $handed_by_signed_at
 * @property string|null $received_by_user_id
 * @property string|null $received_by_name
 * @property string|null $received_by_position
 * @property \Illuminate\Support\Carbon|null $received_by_signed_at
 * @property string|null $witness_user_id
 * @property string|null $witness_name
 * @property string|null $witness_position
 * @property \Illuminate\Support\Carbon|null $witness_signed_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class DeliveryOrder extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'purchase_order_id',
        'do_number',
        'tracking_number',
        'delivery_address',
        'status', // shipped, delivered, received
        'handed_by_user_id',
        'handed_by_name',
        'handed_by_position',
        'handed_by_signed_at',
        'received_by_user_id',
        'received_by_name',
        'received_by_position',
        'received_by_signed_at',
        'witness_user_id',
        'witness_name',
        'witness_position',
        'witness_signed_at',
    ];

    protected $casts = [
        'handed_by_signed_at' => 'datetime',
        'received_by_signed_at' => 'datetime',
        'witness_signed_at' => 'datetime',
    ];

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function goodsReceipts()
    {
        return $this->hasMany(GoodsReceipt::class, 'delivery_order_id');
    }

    public function handedByUser()
    {
        return $this->belongsTo(\App\Domain\Auth\Models\User::class, 'handed_by_user_id');
    }

    public function receivedByUser()
    {
        return $this->belongsTo(\App\Domain\Auth\Models\User::class, 'received_by_user_id');
    }

    public function witnessUser()
    {
        return $this->belongsTo(\App\Domain\Auth\Models\User::class, 'witness_user_id');
    }

    public function isFullySigned(): bool
    {
        return $this->handed_by_signed_at !== null &&
               $this->received_by_signed_at !== null;
    }
}
