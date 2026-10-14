<?php

namespace App\Domain\CrowdBuy\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $crowd_buy_order_id
 * @property string $external_id
 * @property string|null $transaction_id
 * @property float $amount
 * @property string|null $payment_type
 * @property string|null $payment_method
 * @property string $status
 * @property array|null $payment_info
 * @property array|null $raw_response
 * @property \Illuminate\Support\Carbon|null $paid_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class CrowdBuyPayment extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'crowd_buy_order_id',
        'external_id',
        'transaction_id',
        'amount',
        'payment_type',
        'payment_method',
        'status',
        'payment_info',
        'raw_response',
        'paid_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'payment_info' => 'array',
        'raw_response' => 'array',
        'paid_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(CrowdBuyOrder::class, 'crowd_buy_order_id');
    }
}
