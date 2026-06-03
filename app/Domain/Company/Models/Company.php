<?php

namespace App\Domain\Company\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Domain\Auth\Models\User;
use App\Domain\Catalogue\Models\Catalogue;
use App\Support\Tax\TaxIdFormatter;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Company extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'owner_id',
        'name',
        'type', // buyer, vendor
        'status', // pending, approved, rejected
        'verification_notes',
        'tax_id',
        'country', // ID, MY, SG
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

    protected $appends = ['formatted_tax_id'];

    /**
     * Get the formatted tax ID.
     */
    public function getFormattedTaxIdAttribute(): string
    {
        return TaxIdFormatter::format($this->tax_id, $this->country ?? 'ID');
    }

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
