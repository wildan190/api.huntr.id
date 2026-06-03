<?php

namespace App\Domain\Order\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

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
