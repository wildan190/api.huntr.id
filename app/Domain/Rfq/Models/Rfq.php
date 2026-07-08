<?php

namespace App\Domain\Rfq\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Domain\Auth\Models\User;
use App\Domain\Company\Models\Company;
use App\Domain\Proposal\Models\Proposal;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Rfq extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'company_id',
        'user_id',
        'title',
        'description',
        'document_path',
        'status', // draft, pending_approval, approved, active, awarded, closed
        'duration_days',
        'approved_by',
        'approved_at',
        'delivery_point',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
        'duration_days' => 'integer',
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
