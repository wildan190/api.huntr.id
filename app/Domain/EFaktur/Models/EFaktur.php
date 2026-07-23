<?php

namespace App\Domain\EFaktur\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string|null $bast_id
 * @property string|null $po_id
 * @property string|null $invoice_id
 * @property string|null $nofa
 * @property string|null $transaction_id
 * @property string $status
 * @property string|null $no_invoice
 * @property int|null $masa_pajak
 * @property int|null $tahun_pajak
 * @property string|null $tanggal_faktur
 * @property float|null $dpp
 * @property float|null $ppn
 * @property array|null $raw_request
 * @property array|null $raw_response
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
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
