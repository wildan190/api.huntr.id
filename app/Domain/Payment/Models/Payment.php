<?php

namespace App\Domain\Payment\Models;

use App\Domain\Order\Models\Invoice;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

/**
 * @property string $id
 * @property string $invoice_id
 * @property string $external_id
 * @property string|null $transaction_id
 * @property float $amount
 * @property string $payment_type
 * @property string $payment_method
 * @property string $status
 * @property array|null $payment_info
 * @property array|null $raw_response
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class Payment extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'invoice_id',
        'external_id',
        'transaction_id',
        'amount',
        'payment_type',
        'payment_method',
        'status',
        'payment_info',
        'raw_response',
    ];

    protected $casts = [
        'payment_info' => 'array',
        'raw_response' => 'array',
    ];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}
