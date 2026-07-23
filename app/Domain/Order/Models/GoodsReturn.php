<?php

namespace App\Domain\Order\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * GoodsReturn (Pengembalian Barang)
 *
 * Represents a goods return request due to defects, damage, or other quality issues.
 *
 * @property string $id
 * @property string|null $bast_id
 * @property string|null $po_id
 * @property string|null $goods_receipt_id
 * @property string|null $buyer_company_id
 * @property string|null $vendor_company_id
 * @property string $return_number
 * @property string|null $return_date
 * @property string $status
 * @property string|null $return_reason
 * @property array|null $items
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class GoodsReturn extends Model
{
    use \Illuminate\Database\Eloquent\Concerns\HasUuids;

    protected $table = 'returns';

    protected $fillable = [
        'bast_id',
        'po_id',
        'goods_receipt_id',
        'buyer_company_id',
        'vendor_company_id',
        'return_number',
        'return_date',
        'status',
        'return_reason',
        'items',
        'total_return_value',
        'return_description',
        'photos',
        'courier_name',
        'tracking_number',
        'return_address',
        'shipped_date',
        'received_at_vendor',
        'vendor_receiving_notes',
        'inspection_status',
        'inspection_notes',
        'inspected_by_user_id',
        'inspected_at',
        'approved_by_user_id',
        'approved_at',
        'document_path',
        'document_url',
        'created_by',
        'resolution_type',
        'resolution_status',
        'resolution_details',
        'vendor_proposal_notes',
        'buyer_response_notes',
        'resolution_proposed_at',
        'resolution_approved_at',
        'resolution_proposed_by',
        'resolution_approved_by',
    ];

    protected $casts = [
        'return_date' => 'date',
        'items' => 'json',
        'photos' => 'json',
        'resolution_details' => 'json',
        'shipped_date' => 'datetime',
        'received_at_vendor' => 'datetime',
        'inspected_at' => 'datetime',
        'approved_at' => 'datetime',
        'resolution_proposed_at' => 'datetime',
        'resolution_approved_at' => 'datetime',
        'total_return_value' => 'decimal:2',
    ];

    public function bast(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Receipt\Models\Bast::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'po_id');
    }

    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Receipt\Models\GoodsReceipt::class);
    }

    public function buyerCompany(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Company\Models\Company::class, 'buyer_company_id');
    }

    public function vendorCompany(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Company\Models\Company::class, 'vendor_company_id');
    }

    public function inspectedByUser(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Auth\Models\User::class, 'inspected_by_user_id');
    }

    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Auth\Models\User::class, 'approved_by_user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Auth\Models\User::class, 'created_by');
    }

    public function resolutionProposedBy(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Auth\Models\User::class, 'resolution_proposed_by');
    }

    public function resolutionApprovedBy(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Auth\Models\User::class, 'resolution_approved_by');
    }

    public function debitNote(): HasOne
    {
        return $this->hasOne(DebitNote::class, 'return_id');
    }

    /**
     * Generate return number automatically
     */
    public static function generateReturnNumber(): string
    {
        $date = now()->format('Ymd');
        $lastReturn = self::whereDate('created_at', now())
            ->orderBy('created_at', 'desc')
            ->first();
        
        $sequence = ($lastReturn ? (int)substr($lastReturn->return_number, -3) : 0) + 1;
        
        return sprintf('RET-%s-%03d', $date, $sequence);
    }

    /**
     * Calculate total return value from items
     */
    public function calculateTotalReturnValue(): float
    {
        if (!$this->items) {
            return 0;
        }

        $total = 0;
        foreach ($this->items as $item) {
            $itemTotal = ($item['quantity_returned'] ?? 0) * ($item['unit_price'] ?? 0);
            $total += $itemTotal;
        }

        return $total;
    }

    /**
     * Check if return can be inspected
     */
    public function canBeInspected(): bool
    {
        return in_array($this->status, ['received', 'in_transit']) && $this->inspection_status === 'pending';
    }

    /**
     * Check if return can be approved
     */
    public function canBeApproved(): bool
    {
        return $this->inspection_status === 'approved' && $this->status !== 'processed';
    }
}
