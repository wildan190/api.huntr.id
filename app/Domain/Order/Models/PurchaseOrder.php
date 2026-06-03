<?php

namespace App\Domain\Order\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Domain\Rfq\Models\Rfq;
use App\Domain\Company\Models\Company;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

class PurchaseOrder extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'buyer_company_id',
        'rfq_id',
        'vendor_id',
        'po_number',
        'vendor_name',
        'department',
        'currency',
        'purchase_category',
        'purchase_type',
        'order_date',
        'expected_receiving_date',
        'status',
        'is_historical',
        'created_by',
        'approved_by',
    ];

    protected $casts = [
        'order_date'              => 'date',
        'expected_receiving_date' => 'date',
        'is_historical'           => 'boolean',
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
}
