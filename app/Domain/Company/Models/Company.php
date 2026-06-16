<?php

namespace App\Domain\Company\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use App\Domain\Auth\Models\User;
use App\Domain\Catalogue\Models\Catalogue;
use App\Support\Tax\TaxIdFormatter;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Company extends Model
{
    use HasFactory, HasUuids, Notifiable;

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

    /**
     * Users who can approve on behalf of this company (managers + owner).
     */
    public function approvers()
    {
        $users = $this->users()->with('roles')->get()
            ->filter(fn (User $user) => $user->hasRole('manager'));

        $owner = User::find($this->owner_id);
        if ($owner && ! $users->contains('id', $owner->id)) {
            $users->push($owner);
        }

        return $users->unique('id')->values();
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
