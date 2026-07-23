<?php

namespace App\Domain\Order\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

/**
 * @property string $id
 * @property string $purchase_order_id
 * @property string|null $pr_reference_number
 * @property string|null $inventory_code
 * @property string|null $inventory_name
 * @property string|null $category
 * @property string|null $specifications
 * @property string|null $uom
 * @property int $qty
 * @property float|null $unit_price
 * @property float|null $amount
 * @property float|null $tax_amount
 * @property float|null $total_amount
 * @property string|null $currency
 * @property float|null $exchange_rate
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class HistoricalPoItem extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'historical_po_items';

    protected $fillable = [
        'purchase_order_id',
        'pr_reference_number',
        'inventory_code',
        'inventory_name',
        'category',
        'specifications',
        'uom',
        'qty',
        'unit_price',
        'amount',
        'tax_amount',
        'total_amount',
        'currency',
        'exchange_rate',
        'clerk',
        'created_by',
        'approved_by',
        'order_date',
        'expected_receiving_date',
    ];

    protected $casts = [
        'order_date'              => 'date',
        'expected_receiving_date' => 'date',
    ];

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }
}
