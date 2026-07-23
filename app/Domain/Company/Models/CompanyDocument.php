<?php

namespace App\Domain\Company\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

/**
 * @property string $id
 * @property string $company_id
 * @property string $name
 * @property string $type
 * @property string|null $file_path
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class CompanyDocument extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'company_id',
        'name',
        'type',
        'file_path',
    ];

    protected $appends = ['url'];

    public function getUrlAttribute(): string
    {
        /** @var \Illuminate\Filesystem\FilesystemAdapter $storage */
        $storage = \Illuminate\Support\Facades\Storage::disk(config('filesystems.default'));
        return $storage->url($this->file_path);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
