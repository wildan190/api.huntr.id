<?php

namespace App\Domain\Receipt\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Domain\Order\Models\DeliveryOrder;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

/**
 * @property string $id
 * @property string $delivery_order_id
 * @property int $received_qty
 * @property string|null $handover_document_path
 * @property string $status
 * @property array|null $items_inspection
 * @property array|null $accepted_items
 * @property array|null $rejected_items
 * @property string|null $inspection_notes
 * @property string|null $inspection_status
 * @property \Illuminate\Support\Carbon|null $inspected_at
 * @property string|null $inspected_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class GoodsReceipt extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'delivery_order_id',
        'received_qty',
        'handover_document_path',
        'status', // completed
        'items_inspection',
        'accepted_items',
        'rejected_items',
        'inspection_notes',
        'inspection_status',
        'inspected_at',
        'inspected_by',
    ];

    protected $casts = [
        'items_inspection' => 'json',
        'accepted_items' => 'json',
        'rejected_items' => 'json',
        'inspected_at' => 'datetime',
    ];

    public function deliveryOrder()
    {
        return $this->belongsTo(DeliveryOrder::class, 'delivery_order_id');
    }

    public function inspectedBy()
    {
        return $this->belongsTo(\App\Domain\Auth\Models\User::class, 'inspected_by');
    }

    public function returns()
    {
        return $this->hasMany(\App\Domain\Order\Models\GoodsReturn::class, 'goods_receipt_id');
    }

    /**
     * Check if there are rejected items
     */
    public function hasRejectedItems(): bool
    {
        return !empty($this->rejected_items) && count($this->rejected_items) > 0;
    }

    /**
     * Get total quantity of rejected items
     */
    public function getTotalRejectedQty(): int
    {
        if (!$this->rejected_items) {
            return 0;
        }

        $total = 0;
        foreach ($this->rejected_items as $item) {
            $total += ($item['rejected_qty'] ?? 0);
        }

        return $total;
    }
}
