<?php

namespace App\Domain\Subscription\Models;

use App\Domain\Company\Models\Company;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanySubscription extends Model
{
    use HasUuids;

    public const UPFRONT_RATE = 0.015;
    public const OVERFLOW_TRANSACTION_FEE = 'transaction_fee';
    public const OVERFLOW_RENEWAL_REQUIRED = 'renewal_required';

    protected $fillable = [
        'company_id', 'plan', 'status', 'overflow_strategy', 'upfront_fee', 'gmv_limit',
        'current_realized_gmv', 'reserved_gmv', 'starts_at', 'ends_at',
    ];

    protected $casts = [
        'upfront_fee' => 'decimal:2',
        'gmv_limit' => 'decimal:2',
        'current_realized_gmv' => 'decimal:2',
        'reserved_gmv' => 'decimal:2',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $subscription): void {
            $expectedUpfrontFee = round((float) $subscription->gmv_limit * self::UPFRONT_RATE, 2);

            if (round((float) $subscription->upfront_fee, 2) !== $expectedUpfrontFee) {
                throw new \InvalidArgumentException('Biaya subscription harus tepat 1,5% dari kuota GMV.');
            }

            if ($subscription->starts_at && $subscription->ends_at && $subscription->ends_at->greaterThan($subscription->starts_at->copy()->addYear())) {
                throw new \InvalidArgumentException('Masa berlaku subscription tidak boleh lebih dari satu tahun.');
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function remainingGmv(): float
    {
        return max(0, (float) $this->gmv_limit - (float) $this->current_realized_gmv - (float) $this->reserved_gmv);
    }
}
