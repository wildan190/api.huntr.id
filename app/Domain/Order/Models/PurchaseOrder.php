<?php

namespace App\Domain\Order\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Domain\Rfq\Models\Rfq;
use App\Domain\Company\Models\Company;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

/**
 * @property string $id
 * @property string $buyer_company_id
 * @property string $rfq_id
 * @property string $vendor_id
 * @property string $proposal_id
 * @property string $po_number
 * @property string $vendor_name
 * @property string $department
 * @property string $currency
 * @property float $total_amount
 * @property string $purchase_category
 * @property string $purchase_type
 * @property \Illuminate\Support\Carbon|null $order_date
 * @property \Illuminate\Support\Carbon|null $expected_receiving_date
 * @property string $delivery_point
 * @property string $status
 * @property string $delivery_status
 * @property array|null $tracking_timeline
 * @property bool $is_historical
 * @property string $created_by
 * @property string $approved_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property Company|null $buyer
 * @property Company|null $vendor
 */
class PurchaseOrder extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'buyer_company_id',
        'rfq_id',
        'vendor_id',
        'proposal_id',
        'po_number',
        'vendor_name',
        'department',
        'currency',
        'total_amount',
        'purchase_category',
        'purchase_type',
        'order_date',
        'expected_receiving_date',
        'delivery_point',
        'status',
        'delivery_status',
        'tracking_timeline',
        'is_historical',
        'created_by',
        'approved_by',
    ];

    protected $casts = [
        'order_date'              => 'date',
        'expected_receiving_date' => 'date',
        'is_historical'           => 'boolean',
        'tracking_timeline'       => 'array',
    ];

    public function creator()
    {
        return $this->belongsTo(\App\Domain\Auth\Models\User::class, 'created_by');
    }

    public function approver()
    {
        return $this->belongsTo(\App\Domain\Auth\Models\User::class, 'approved_by');
    }

    public function rfq()
    {
        return $this->belongsTo(Rfq::class);
    }

    public function buyer()
    {
        return $this->belongsTo(Company::class, 'buyer_company_id');
    }

    public function vendor()
    {
        return $this->belongsTo(Company::class, 'vendor_id');
    }

    public function historicalItems()
    {
        return $this->hasMany(HistoricalPoItem::class, 'purchase_order_id');
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class, 'purchase_order_id');
    }

    public function deliveryOrders()
    {
        return $this->hasMany(DeliveryOrder::class, 'purchase_order_id');
    }

    public function basts()
    {
        return $this->hasMany(Bast::class, 'po_id');
    }

    public function efakturs()
    {
        return $this->hasMany(\App\Domain\EFaktur\Models\EFaktur::class, 'po_id');
    }
}
