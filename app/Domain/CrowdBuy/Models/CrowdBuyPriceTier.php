<?php

namespace App\Domain\CrowdBuy\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $campaign_id
 * @property int $min_quantity
 * @property int|null $max_quantity
 * @property float $unit_price
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class CrowdBuyPriceTier extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'campaign_id',
        'min_quantity',
        'max_quantity',
        'unit_price',
    ];

    protected $casts = [
        'min_quantity' => 'integer',
        'max_quantity' => 'integer',
        'unit_price' => 'decimal:2',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(CrowdBuyCampaign::class, 'campaign_id');
    }
}
