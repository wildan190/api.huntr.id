<?php

namespace App\Domain\Catalogue\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Domain\Company\Models\Company;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Catalogue extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'company_id',
        'item_code',
        'name',
        'category',
        'specifications',
        'uom',
        'image_path',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
