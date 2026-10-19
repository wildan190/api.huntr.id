<?php

namespace App\Domain\CrowdBuy\Models;

use App\Domain\Auth\Models\User;
use App\Domain\Catalogue\Models\Catalogue;
use App\Domain\Company\Models\Company;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * @property string $id
 * @property string $code
 * @property string $supplier_company_id
 * @property string $created_by
 * @property string|null $catalogue_id
 * @property string $title
 * @property string|null $description
 * @property string $category
 * @property string $unit
 * @property float $base_price
 * @property int $target_quantity
 * @property int|null $max_quantity
 * @property int $min_order_qty
 * @property int|null $max_order_qty
 * @property int $current_quantity
 * @property string $status
 * @property \Illuminate\Support\Carbon $starts_at
 * @property \Illuminate\Support\Carbon $ends_at
 * @property int|null $lead_time_days
 * @property string|null $delivery_terms
 * @property string|null $return_policy
 * @property string|null $image_path
 * @property string|null $reviewed_by
 * @property \Illuminate\Support\Carbon|null $reviewed_at
 * @property string|null $review_notes
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class CrowdBuyCampaign extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'code',
        'creator_type',
        'campaign_type',
        'is_monthly_recurring',
        'monthly_delivery_day',
        'historical_metrics',
        'initiator_company_id',
        'invite_code',
        'supplier_company_id',
        'created_by',
        'catalogue_id',
        'title',
        'description',
        'category',
        'unit',
        'base_price',
        'target_price',
        'target_quantity',
        'max_quantity',
        'min_order_qty',
        'max_order_qty',
        'current_quantity',
        'status',
        'starts_at',
        'ends_at',
        'lead_time_days',
        'delivery_terms',
        'return_policy',
        'image_path',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
    ];

    protected $casts = [
        'base_price' => 'decimal:2',
        'target_price' => 'decimal:2',
        'target_quantity' => 'integer',
        'max_quantity' => 'integer',
        'min_order_qty' => 'integer',
        'max_order_qty' => 'integer',
        'current_quantity' => 'integer',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'lead_time_days' => 'integer',
        'is_monthly_recurring' => 'boolean',
        'monthly_delivery_day' => 'integer',
        'historical_metrics' => 'array',
    ];

    protected $appends = [
        'image_url',
        'progress_percentage',
        'is_target_reached',
        'days_remaining',
        'is_active',
        'is_buyer_initiated',
        'is_auto_demand',
        'monthly_delivery_schedule',
    ];

    public function getIsAutoDemandAttribute(): bool
    {
        return $this->campaign_type === 'auto_demand_aggregation' || (bool) $this->is_monthly_recurring;
    }

    public function getMonthlyDeliveryScheduleAttribute(): string
    {
        $day = $this->monthly_delivery_day ?: 27;
        return "Barang akan dikirim setiap tanggal {$day} setiap bulannya ke lokasi sesuai delivery point.";
    }

    public function getIsBuyerInitiatedAttribute(): bool
    {
        return $this->creator_type === 'buyer';
    }

    public function getImageUrlAttribute(): ?string
    {
        if (!$this->image_path) {
            return null;
        }

        if (filter_var($this->image_path, FILTER_VALIDATE_URL)) {
            return $this->image_path;
        }

        /** @var \Illuminate\Filesystem\FilesystemAdapter $storage */
        $storage = Storage::disk(config('filesystems.default'));
        return $storage->url($this->image_path);
    }

    public function getProgressPercentageAttribute(): float
    {
        if ($this->target_quantity <= 0) {
            return 0.0;
        }

        $pct = ($this->current_quantity / $this->target_quantity) * 100;
        return (float) round(min($pct, 100), 2);
    }

    public function getIsTargetReachedAttribute(): bool
    {
        return $this->current_quantity >= $this->target_quantity;
    }

    public function getDaysRemainingAttribute(): int
    {
        if (!$this->ends_at || now()->greaterThan($this->ends_at)) {
            return 0;
        }

        return (int) ceil(now()->diffInSeconds($this->ends_at, false) / 86400);
    }

    public function getIsActiveAttribute(): bool
    {
        return in_array($this->status, ['active', 'bidding']) && now()->between($this->starts_at, $this->ends_at);
    }

    /**
     * Determine unit price based on tiers if defined, otherwise base price.
     */
    public function getEffectiveUnitPrice(int $cumulativeQuantity): float
    {
        $tier = $this->priceTiers()
            ->where('min_quantity', '<=', $cumulativeQuantity)
            ->where(function ($q) use ($cumulativeQuantity) {
                $q->whereNull('max_quantity')
                  ->orWhere('max_quantity', '>=', $cumulativeQuantity);
            })
            ->orderBy('unit_price', 'asc')
            ->first();

        return $tier ? (float) $tier->unit_price : (float) $this->base_price;
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'supplier_company_id');
    }

    public function initiatorCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'initiator_company_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function catalogue(): BelongsTo
    {
        return $this->belongsTo(Catalogue::class);
    }

    public function priceTiers(): HasMany
    {
        return $this->hasMany(CrowdBuyPriceTier::class, 'campaign_id')->orderBy('min_quantity', 'asc');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(CrowdBuyOrder::class, 'campaign_id');
    }

    public function bids(): HasMany
    {
        return $this->hasMany(CrowdBuyBid::class, 'campaign_id')->orderBy('offered_unit_price', 'asc');
    }

    public function histories(): HasMany
    {
        return $this->hasMany(CrowdBuyCampaignHistory::class, 'campaign_id')->latest();
    }
}
