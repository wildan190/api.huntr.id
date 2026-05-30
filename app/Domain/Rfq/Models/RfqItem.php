<?php

namespace App\Domain\Rfq\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Domain\Catalogue\Models\Catalogue;

class RfqItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'rfq_id',
        'catalogue_id',
        'qty',
        'estimated_price',
        'expected_date',
    ];

    public function rfq()
    {
        return $this->belongsTo(Rfq::class);
    }

    public function catalogue()
    {
        return $this->belongsTo(Catalogue::class);
    }
}
