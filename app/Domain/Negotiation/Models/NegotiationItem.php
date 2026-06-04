<?php

namespace App\Domain\Negotiation\Models;

use App\Domain\Proposal\Models\ProposalItem;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class NegotiationItem extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'negotiation_id',
        'proposal_item_id',
        'negotiated_price',
        'negotiated_qty',
    ];

    public function negotiation()
    {
        return $this->belongsTo(Negotiation::class);
    }

    public function proposalItem()
    {
        return $this->belongsTo(ProposalItem::class);
    }
}
