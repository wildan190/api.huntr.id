<?php

namespace App\Domain\Proposal\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Domain\Rfq\Models\Rfq;
use App\Domain\Company\Models\Company;

class Proposal extends Model
{
    use HasFactory;

    protected $fillable = [
        'rfq_id',
        'company_id',
        'price_offer',
        'delivery_days',
        'warranty_months',
        'document_path',
        'payment_term',
        'status',
        'winner_status',
        'awarded_at',
        'awarded_by_user_id',
        'approved_at',
        'approved_by_user_id',
    ];

    public function rfq()
    {
        return $this->belongsTo(Rfq::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function items()
    {
        return $this->hasMany(ProposalItem::class);
    }
}
