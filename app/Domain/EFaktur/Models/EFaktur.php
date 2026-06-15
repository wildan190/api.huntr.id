<?php

namespace App\Domain\EFaktur\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class EFaktur extends Model
{
    use HasUuids;

    protected $table = 'efakturs';

    protected $fillable = [
        'bast_id',
        'po_id',
        'invoice_id',
        'nofa',           // Nomor Faktur Pajak (assigned by DJP)
        'transaction_id', // Pajak.io transactionId (UUID)
        'status',         // CREATED, APPROVED, CANCELLED, etc.
        'no_invoice',     // PO number used as invoice reference
        'masa_pajak',
        'tahun_pajak',
        'tanggal_faktur',
        'dpp',
        'ppn',
        'raw_request',
        'raw_response',
    ];

    protected $casts = [
        'raw_request'  => 'array',
        'raw_response' => 'array',
        'dpp'          => 'float',
        'ppn'          => 'float',
    ];

    public function bast()
    {
        return $this->belongsTo(\App\Domain\Order\Models\Bast::class);
    }

    public function purchaseOrder()
    {
        return $this->belongsTo(\App\Domain\Order\Models\PurchaseOrder::class, 'po_id');
    }

    public function invoice()
    {
        return $this->belongsTo(\App\Domain\Order\Models\Invoice::class);
    }
}
