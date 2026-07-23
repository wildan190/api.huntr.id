<?php

namespace App\Domain\Proposal\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Domain\Rfq\Models\Rfq;
use App\Domain\Company\Models\Company;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

/**
 * @property string $id
 * @property string $rfq_id
 * @property string $company_id
 * @property float $price_offer
 * @property int $delivery_days
 * @property int $warranty_months
 * @property string $document_path
 * @property string $payment_term
 * @property string $status
 * @property string $winner_status
 * @property \Illuminate\Support\Carbon|null $awarded_at
 * @property string $awarded_by_user_id
 * @property \Illuminate\Support\Carbon|null $approved_at
 * @property string $approved_by_user_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class Proposal extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'rfq_id',
        'company_id',
        'price_offer',
        'delivery_days',
        'warranty_months',
        'document_path',
        'payment_term',
        'status',
        'winner_status',
        'awarded_at',
        'awarded_by_user_id',
        'approved_at',
        'approved_by_user_id',
    ];

    protected $appends = ['document_url'];

    public function getDocumentUrlAttribute(): ?string
    {
        if (!$this->document_path) {
            return null;
        }
        /** @var \Illuminate\Filesystem\FilesystemAdapter $storage */
        $storage = \Illuminate\Support\Facades\Storage::disk(config('filesystems.default'));
        return $storage->url($this->document_path);
    }

    public function rfq()
    {
        return $this->belongsTo(Rfq::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function items()
    {
        return $this->hasMany(ProposalItem::class);
    }

    public function negotiations()
    {
        return $this->hasMany(\App\Domain\Negotiation\Models\Negotiation::class);
    }

    public function acceptedNegotiation()
    {
        return $this->hasOne(\App\Domain\Negotiation\Models\Negotiation::class)->where('status', 'accepted')->latest();
    }
}
