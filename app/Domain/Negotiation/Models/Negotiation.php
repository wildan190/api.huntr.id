<?php

namespace App\Domain\Negotiation\Models;

use App\Domain\Proposal\Models\Proposal;
use App\Domain\Auth\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

/**
 * @property string $id
 * @property string $proposal_id
 * @property string $buyer_id
 * @property string $status
 * @property string|null $payment_scheme
 * @property string|null $delivery_terms
 * @property string|null $buyer_remarks
 * @property string|null $vendor_remarks
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class Negotiation extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'proposal_id',
        'buyer_id',
        'status', // pending, accepted, declined
        'payment_scheme',
        'delivery_terms',
        'buyer_remarks',
        'vendor_remarks',
    ];

    public function proposal()
    {
        return $this->belongsTo(Proposal::class);
    }

    public function buyer()
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function items()
    {
        return $this->hasMany(NegotiationItem::class);
    }
}
