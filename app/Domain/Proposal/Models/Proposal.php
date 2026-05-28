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
        'company_id', // vendor
        'price_offer',
        'delivery_days',
        'warranty_months',
        'status', // submitted, accepted, rejected
    ];

    public function rfq()
    {
        return $this->belongsTo(Rfq::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
