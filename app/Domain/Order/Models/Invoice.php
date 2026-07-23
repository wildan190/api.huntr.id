<?php

namespace App\Domain\Order\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

/**
 * @property string $id
 * @property string $purchase_order_id
 * @property string $type
 * @property float $amount
 * @property string $status
 * @property float|null $base_amount
 * @property float|null $platform_fee
 * @property float|null $ppn_platform
 * @property float|null $midtrans_fee
 * @property float|null $pph23
 * @property float|null $ppn_fee
 * @property float|null $total_amount
 * @property string|null $vendor_signed_name
 * @property \Illuminate\Support\Carbon|null $vendor_signed_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
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
        'ppn_platform',
        'midtrans_fee',
        'pph23',
        'ppn_fee',
        'total_amount',
        'vendor_signed_name',
        'vendor_signed_at',
    ];

    protected $casts = [
        'vendor_signed_at' => 'datetime',
    ];

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }
}
