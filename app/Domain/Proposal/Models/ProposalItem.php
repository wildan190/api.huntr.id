<?php

namespace App\Domain\Proposal\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Domain\Rfq\Models\RfqItem;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

/**
 * @property string $id
 * @property string $proposal_id
 * @property string $rfq_item_id
 * @property float $price_offer
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class ProposalItem extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'proposal_id',
        'rfq_item_id',
        'price_offer',
    ];

    public function proposal()
    {
        return $this->belongsTo(Proposal::class);
    }

    public function rfqItem()
    {
        return $this->belongsTo(RfqItem::class);
    }
}
