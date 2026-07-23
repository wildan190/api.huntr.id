<?php

namespace App\Domain\Order\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * BAST (Berita Acara Serah Terima - Handover Report)
 *
 * Represents a formal handover document between vendor and buyer during goods receipt.
 * Located in Order domain as it's part of the purchase order lifecycle.
 *
 * @property string $id
 * @property string|null $goods_receipt_id
 * @property string|null $po_id
 * @property string|null $buyer_company_id
 * @property string|null $vendor_company_id
 * @property string $bast_number
 * @property string|null $bast_date
 * @property string $status
 * @property array|null $items
 * @property string|null $handover_notes
 * @property string|null $witness_notes
 * @property string|null $handed_by_user_id
 * @property string|null $handed_by_name
 * @property string|null $handed_by_position
 * @property \Illuminate\Support\Carbon|null $handed_by_signed_at
 * @property string|null $received_by_user_id
 * @property string|null $received_by_name
 * @property string|null $received_by_position
 * @property \Illuminate\Support\Carbon|null $received_by_signed_at
 * @property string|null $witness_user_id
 * @property string|null $witness_name
 * @property string|null $witness_position
 * @property \Illuminate\Support\Carbon|null $witness_signed_at
 * @property string|null $document_path
 * @property string|null $document_url
 * @property string|null $created_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
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
        return $this->belongsTo(\App\Domain\Receipt\Models\GoodsReceipt::class);
    }

    public function efaktur(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(\App\Domain\EFaktur\Models\EFaktur::class, 'bast_id');
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
     * Generate BAST number automatically (thread-safe with retry logic)
     */
    public static function generateBastNumber(): string
    {
        $date = now()->format('Ymd');
        $maxRetries = 5;
        $attempt = 0;
        
        while ($attempt < $maxRetries) {
            try {
                // Use database lock to prevent race condition
                return DB::transaction(function () use ($date) {
                    // Lock for update - this will wait if another transaction is holding the lock
                    $lastBast = self::where('bast_number', 'like', "BAST-{$date}-%")
                        ->orderBy('bast_number', 'desc')
                        ->lockForUpdate()
                        ->first();
                    
                    if ($lastBast) {
                        // Extract sequence number from last BAST
                        $lastSequence = (int) substr($lastBast->bast_number, -3);
                        $sequence = $lastSequence + 1;
                    } else {
                        $sequence = 1;
                    }
                    
                    return sprintf('BAST-%s-%03d', $date, $sequence);
                }, 5); // Set deadlock timeout to 5 attempts
            } catch (\Exception $e) {
                $attempt++;
                if ($attempt >= $maxRetries) {
                    throw $e;
                }
                // Wait a bit before retrying (exponential backoff)
                usleep(100000 * $attempt); // 100ms, 200ms, 300ms, etc.
            }
        }
        
        throw new \Exception('Failed to generate BAST number after multiple attempts');
    }

    /**
     * Check if BAST is fully signed by all parties
     */
    public function isFullySigned(): bool
    {
        return $this->handed_by_signed_at !== null &&
               $this->received_by_signed_at !== null;
    }

    /**
     * Mark BAST as completed
     */
    public function markCompleted(): void
    {
        if ($this->isFullySigned()) {
            $this->update(['status' => 'completed']);
            event(new \App\Domain\Order\Events\BastCompletedEvent($this));
        }
    }
}
