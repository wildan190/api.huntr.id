<?php

namespace App\Domain\Order\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DebitNote (Nota Debit)
 * 
 * Represents a debit note issued against an invoice for returns, adjustments, or chargebacks.
 */
class DebitNote extends Model
{
    use \Illuminate\Database\Eloquent\Concerns\HasUuids;

    protected $table = 'debit_notes';

    protected $fillable = [
        'invoice_id',
        'return_id',
        'po_id',
        'buyer_company_id',
        'vendor_company_id',
        'debit_note_number',
        'debit_note_date',
        'type',
        'status',
        'line_items',
        'subtotal',
        'tax_amount',
        'tax_rate',
        'total_amount',
        'currency',
        'description',
        'reason_for_debit',
        'related_invoice_number',
        'attachments',
        'issued_by_user_id',
        'issued_at',
        'acknowledged_by_user_id',
        'acknowledged_at',
        'acknowledgment_notes',
        'settled_by_user_id',
        'settled_at',
        'settlement_method',
        'settlement_notes',
        'dispute_reason',
        'disputed_by_user_id',
        'disputed_at',
        'dispute_resolution',
        'document_path',
        'document_url',
        'created_by',
    ];

    protected $casts = [
        'debit_note_date' => 'date',
        'line_items' => 'json',
        'attachments' => 'json',
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'issued_at' => 'datetime',
        'acknowledged_at' => 'datetime',
        'settled_at' => 'datetime',
        'disputed_at' => 'datetime',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Order\Models\Invoice::class);
    }

    public function return(): BelongsTo
    {
        return $this->belongsTo(GoodsReturn::class, 'return_id');
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'po_id');
    }

    public function buyerCompany(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Company\Models\Company::class, 'buyer_company_id');
    }

    public function vendorCompany(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Company\Models\Company::class, 'vendor_company_id');
    }

    public function issuedByUser(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Auth\Models\User::class, 'issued_by_user_id');
    }

    public function acknowledgedByUser(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Auth\Models\User::class, 'acknowledged_by_user_id');
    }

    public function settledByUser(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Auth\Models\User::class, 'settled_by_user_id');
    }

    public function disputedByUser(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Auth\Models\User::class, 'disputed_by_user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Auth\Models\User::class, 'created_by');
    }

    /**
     * Generate debit note number automatically
     */
    public static function generateDebitNoteNumber(): string
    {
        $date = now()->format('Ymd');
        $lastNote = self::whereDate('created_at', now())
            ->orderBy('created_at', 'desc')
            ->first();
        
        $sequence = ($lastNote ? (int)substr($lastNote->debit_note_number, -3) : 0) + 1;
        
        return sprintf('DN-%s-%03d', $date, $sequence);
    }

    /**
     * Calculate amounts from line items
     */
    public function calculateAmounts(): void
    {
        $this->subtotal = 0;

        if ($this->line_items) {
            foreach ($this->line_items as $item) {
                $this->subtotal += $item['amount'] ?? 0;
            }
        }

        // Parse tax rate (e.g., "10%" -> 0.10)
        $taxRate = (float)str_replace('%', '', $this->tax_rate ?? '0');
        $this->tax_amount = $this->subtotal * ($taxRate / 100);
        $this->total_amount = $this->subtotal + $this->tax_amount;
    }

    /**
     * Check if debit note can be acknowledged
     */
    public function canBeAcknowledged(): bool
    {
        return $this->status === 'issued';
    }

    /**
     * Check if debit note can be settled
     */
    public function canBeSettled(): bool
    {
        return $this->status === 'acknowledged' && !$this->disputed_at;
    }

    /**
     * Check if debit note can be disputed
     */
    public function canBeDisputed(): bool
    {
        return in_array($this->status, ['issued', 'acknowledged']) && !$this->disputed_at;
    }

    /**
     * Mark as disputed
     */
    public function markAsDisputed(string $reason, string $userId): void
    {
        $this->update([
            'status' => 'disputed',
            'dispute_reason' => $reason,
            'disputed_by_user_id' => $userId,
            'disputed_at' => now(),
        ]);
    }

    /**
     * Resolve dispute
     */
    public function resolveDispute(string $resolution, string $userId): void
    {
        $this->update([
            'dispute_resolution' => $resolution,
            'settled_by_user_id' => $userId,
            'settled_at' => now(),
            'status' => 'settled',
        ]);
    }
}
