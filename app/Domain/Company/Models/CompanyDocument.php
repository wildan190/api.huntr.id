<?php

namespace App\Domain\Company\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CompanyDocument extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'name',
        'type',
        'file_path',
    ];

    protected $appends = ['url'];

    public function getUrlAttribute(): string
    {
        return \Illuminate\Support\Facades\Storage::disk(env('FILESYSTEM_DISK', 'public'))->url($this->file_path);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
