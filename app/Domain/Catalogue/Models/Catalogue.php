<?php

namespace App\Domain\Catalogue\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Domain\Company\Models\Company;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

/**
 * @property string $id
 * @property string $company_id
 * @property string $item_code
 * @property string $name
 * @property string $category
 * @property string|null $brand
 * @property string|null $specifications
 * @property array|null $keywords
 * @property string|null $uom
 * @property string|null $image_path
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class Catalogue extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'company_id',
        'item_code',
        'name',
        'category',
        'brand',
        'specifications',
        'keywords',
        'uom',
        'image_path',
    ];

    protected $casts = [
        'keywords' => 'array',
    ];

    protected $appends = ['image_url'];

    public function getImageUrlAttribute(): ?string
    {
        if (!$this->image_path) {
            return null;
        }

        /** @var \Illuminate\Filesystem\FilesystemAdapter $storage */
        $storage = \Illuminate\Support\Facades\Storage::disk(config('filesystems.default'));
        return $storage->url($this->image_path);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
