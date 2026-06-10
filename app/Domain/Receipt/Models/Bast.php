<?php

namespace App\Domain\Receipt\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BAST (Berita Acara Serah Terima - Handover Report)
 * 
 * Represents a formal handover document between vendor and buyer during goods receipt.
 */
class Bast extends Model
{
    use \Illuminate\Database\Eloquent\Concerns\HasUuids;

    protected $table = 'basts';

    protected $fillable = [
        'goods_receipt_id',
        'po_id',
        'buyer_company_id',
        'vendor_company_id',
        'bast_number',
        'bast_date',
        'status',
        'items',
        'handover_notes',
        'witness_notes',
        'handed_by_user_id',
        'handed_by_name',
        'handed_by_position',
        'handed_by_signed_at',
        'received_by_user_id',
        'received_by_name',
        'received_by_position',
        'received_by_signed_at',
        'witness_user_id',
        'witness_name',
        'witness_position',
        'witness_signed_at',
        'document_path',
        'document_url',
        'created_by',
    ];

    protected $casts = [
        'bast_date' => 'date',
        'items' => 'json',
        'handed_by_signed_at' => 'datetime',
        'received_by_signed_at' => 'datetime',
        'witness_signed_at' => 'datetime',
    ];

    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Order\Models\PurchaseOrder::class, 'po_id');
    }

    public function buyerCompany(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Company\Models\Company::class, 'buyer_company_id');
    }

    public function vendorCompany(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Company\Models\Company::class, 'vendor_company_id');
    }

    public function handedByUser(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Auth\Models\User::class, 'handed_by_user_id');
    }

    public function receivedByUser(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Auth\Models\User::class, 'received_by_user_id');
    }

    public function witnessUser(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Auth\Models\User::class, 'witness_user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Auth\Models\User::class, 'created_by');
    }

    /**
     * Generate BAST number automatically
     */
    public static function generateBastNumber(): string
    {
        $date = now()->format('Ymd');
        $lastBast = self::whereDate('created_at', now())
            ->orderBy('created_at', 'desc')
            ->first();
        
        $sequence = ($lastBast ? (int)substr($lastBast->bast_number, -3) : 0) + 1;
        
        return sprintf('BAST-%s-%03d', $date, $sequence);
    }

    /**
     * Check if BAST is fully signed by all parties
     */
    public function isFullySigned(): bool
    {
        return $this->handed_by_signed_at !== null &&
               $this->received_by_signed_at !== null &&
               $this->witness_signed_at !== null;
    }

    /**
     * Mark BAST as completed
     */
    public function markCompleted(): void
    {
        if ($this->isFullySigned()) {
            $this->update(['status' => 'completed']);
        }
    }
}
