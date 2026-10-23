<?php

namespace App\Domain\Rfq\Models;

use App\Domain\Catalogue\Models\Catalogue;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $rfq_id
 * @property string $catalogue_id
 * @property int $qty
 * @property float|null $estimated_price
 * @property string|null $expected_date
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class RfqItem extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'rfq_id',
        'line_type',
        'catalogue_id',
        'item_name',
        'sku',
        'uom',
        'service_scope',
        'acceptance_criteria',
        'service_start_date',
        'service_end_date',
        'qty',
        'estimated_price',
        'expected_date',
    ];

    protected $casts = [
        'service_start_date' => 'date',
        'service_end_date' => 'date',
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
