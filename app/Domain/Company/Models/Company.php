<?php

namespace App\Domain\Company\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Domain\Auth\Models\User;
use App\Domain\Catalogue\Models\Catalogue;

class Company extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'type', // buyer, vendor
        'status', // pending, approved, rejected
        'verification_notes',
        'tax_id',
        'email',
        'phone',
        'region',
        'provincy_country',
        'regency',
        'city',
        'zip_code',
        'address',
        'bank_name',
        'bank_account',
        'bank_account_name',
        'about',
        'industry_type',
        'logo_path',
    ];

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function catalogues()
    {
        return $this->hasMany(Catalogue::class);
    }

    public function documents()
    {
        return $this->hasMany(CompanyDocument::class);
    }
}
