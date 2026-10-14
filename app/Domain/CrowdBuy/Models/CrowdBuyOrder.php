<?php

namespace App\Domain\CrowdBuy\Models;

use App\Domain\Auth\Models\User;
use App\Domain\Company\Models\Company;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property string $id
 * @property string $order_number
 * @property string $campaign_id
 * @property string $buyer_company_id
 * @property string $user_id
 * @property int $quantity
 * @property float $unit_price
 * @property float $subtotal
 * @property float $tax_amount
 * @property float $shipping_cost
 * @property float $total_amount
 * @property array $shipping_address
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $payment_deadline
 * @property \Illuminate\Support\Carbon|null $paid_at
 * @property string|null $carrier
 * @property string|null $tracking_number
 * @property \Illuminate\Support\Carbon|null $shipped_at
 * @property \Illuminate\Support\Carbon|null $delivered_at
 * @property string|null $notes
 * @property string|null $cancellation_reason
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class CrowdBuyOrder extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'order_number',
        'campaign_id',
        'buyer_company_id',
        'user_id',
        'quantity',
        'unit_price',
        'subtotal',
        'tax_amount',
        'shipping_cost',
        'total_amount',
        'shipping_address',
        'status',
        'payment_deadline',
        'paid_at',
        'carrier',
        'tracking_number',
        'shipped_at',
        'delivered_at',
        'notes',
        'cancellation_reason',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'shipping_cost' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'shipping_address' => 'array',
        'payment_deadline' => 'datetime',
        'paid_at' => 'datetime',
        'shipped_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(CrowdBuyCampaign::class, 'campaign_id');
    }

    public function buyerCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'buyer_company_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(CrowdBuyPayment::class, 'crowd_buy_order_id');
    }

    public function latestPayment(): HasOne
    {
        return $this->hasOne(CrowdBuyPayment::class, 'crowd_buy_order_id')->latestOfMany();
    }
}
