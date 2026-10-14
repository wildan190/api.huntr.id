<?php

namespace App\Domain\CrowdBuy\Models;

use App\Domain\Auth\Models\User;
use App\Domain\Company\Models\Company;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $campaign_id
 * @property string $supplier_company_id
 * @property string $supplier_user_id
 * @property float $offered_unit_price
 * @property int $min_order_qty
 * @property int|null $lead_time_days
 * @property string|null $notes
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class CrowdBuyBid extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'campaign_id',
        'supplier_company_id',
        'supplier_user_id',
        'offered_unit_price',
        'min_order_qty',
        'lead_time_days',
        'notes',
        'status',
    ];

    protected $casts = [
        'offered_unit_price' => 'decimal:2',
        'min_order_qty' => 'integer',
        'lead_time_days' => 'integer',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(CrowdBuyCampaign::class, 'campaign_id');
    }

    public function supplierCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'supplier_company_id');
    }

    public function supplierUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supplier_user_id');
    }
}
