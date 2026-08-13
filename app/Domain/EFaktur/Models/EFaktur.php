<?php

namespace App\Domain\EFaktur\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * EFaktur
 *
 * Menyimpan data Faktur Pajak (VAT Out & VAT In) yang diproses melalui PajakExpress.
 *
 * @property string      $id
 * @property string|null $bast_id
 * @property string|null $po_id
 * @property string|null $invoice_id
 * @property string|null $pajak_express_id   ID internal PajakExpress dari response create
 * @property string      $vat_type           VAT_OUT | VAT_IN
 * @property string|null $npwp_penjual       Untuk VAT In
 * @property string      $kd_jenis_transaksi TD.00304 (default), dll
 * @property string|null $nofa               Nomor Faktur Pajak resmi dari DJP
 * @property string      $status             DRAFT | APPROVED | CANCELLED | CREATED
 * @property string|null $no_invoice
 * @property string|null $masa_pajak
 * @property string|null $tahun_pajak
 * @property string|null $tanggal_faktur
 * @property float       $dpp
 * @property float       $ppn
 * @property array|null  $raw_request
 * @property array|null  $raw_response
 */
class EFaktur extends Model
{
    use HasUuids;

    protected $table = 'efakturs';

    protected $fillable = [
        'bast_id',
        'po_id',
        'invoice_id',
        'pajak_express_id',
        'vat_type',
        'npwp_penjual',
        'kd_jenis_transaksi',
        'nofa',
        'status',
        'no_invoice',
        'masa_pajak',
        'tahun_pajak',
        'tanggal_faktur',
        'dpp',
        'ppn',
        'raw_request',
        'raw_response',
        // Legacy — kept for backward compat, not used by new service
        'transaction_id',
    ];

    protected $casts = [
        'raw_request'  => 'array',
        'raw_response' => 'array',
        'dpp'          => 'float',
        'ppn'          => 'float',
    ];

    /* ── Relations ──────────────────────────────────────────────── */

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

    /* ── Helpers ────────────────────────────────────────────────── */

    public function isVatOut(): bool
    {
        return $this->vat_type === 'VAT_OUT';
    }

    public function isVatIn(): bool
    {
        return $this->vat_type === 'VAT_IN';
    }

    public function isDraft(): bool
    {
        return strtoupper($this->status) === 'DRAFT';
    }

    public function isApproved(): bool
    {
        return strtoupper($this->status) === 'APPROVED';
    }

    public function isCancelled(): bool
    {
        return strtoupper($this->status) === 'CANCELLED';
    }
}
