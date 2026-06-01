<?php

namespace App\Domain\Catalogue\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Domain\Company\Models\Company;

class Catalogue extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'item_code',
        'name',
        'category',
        'specifications',
        'uom',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
