<?php

namespace App\Domain\Rfq\Models;

use App\Domain\Auth\Models\User;
use App\Domain\Company\Models\Company;
use App\Domain\Proposal\Models\Proposal;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * @property string $id
 * @property string $company_id
 * @property string $user_id
 * @property string $title
 * @property string|null $description
 * @property string|null $document_path
 * @property string $status
 * @property int|null $duration_days
 * @property string|null $approved_by
 * @property Carbon|null $approved_at
 * @property string|null $delivery_point
 * @property string|null $warehouse_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Rfq extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'company_id',
        'user_id',
        'title',
        'description',
        'document_path',
        'status',
        'duration_days',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
        'rejection_reason',
        'delivery_point',
        'warehouse_id',
        'department',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'duration_days' => 'integer',
    ];

    protected $appends = ['document_url'];

    public function getDocumentUrlAttribute(): ?string
    {
        if (! $this->document_path) {
            return null;
        }
        /** @var FilesystemAdapter $storage */
        $storage = Storage::disk(config('filesystems.default'));

        return $storage->url($this->document_path);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function items()
    {
        return $this->hasMany(RfqItem::class);
    }

    public function proposals()
    {
        return $this->hasMany(Proposal::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
