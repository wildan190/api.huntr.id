<?php

namespace App\Domain\Rfq\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Domain\Catalogue\Models\Catalogue;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

/**
 * @property string $id
 * @property string $rfq_id
 * @property string $catalogue_id
 * @property int $qty
 * @property float|null $estimated_price
 * @property string|null $expected_date
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class RfqItem extends Model
{
    use HasFactory, HasUuids;

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
