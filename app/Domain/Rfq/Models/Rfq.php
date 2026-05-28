<?php

namespace App\Domain\Rfq\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Domain\Company\Models\Company;
use App\Domain\Proposal\Models\Proposal;

class Rfq extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'title',
        'description',
        'status', // draft, pending_manager, active, awarded, closed
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function items()
    {
        return $this->hasMany(RfqItem::class);
    }

    public function proposals()
    {
        return $this->hasMany(Proposal::class);
    }
}
