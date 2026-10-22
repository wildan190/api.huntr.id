<?php

namespace App\Domain\Company\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CompanyPermission extends Model
{
    use HasUuids;

    protected $fillable = ['company_id', 'key', 'name', 'category', 'description', 'is_system'];
    protected $casts = ['is_system' => 'boolean'];
}
