<?php

namespace App\Domain\Company\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use App\Domain\Auth\Models\User;
use App\Domain\Catalogue\Models\Catalogue;
use App\Support\Tax\TaxIdFormatter;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

/**
 * @property string $id
 * @property string $owner_id
 * @property string $name
 * @property string $type
 * @property string $status
 * @property string $verification_tier
 * @property string $verification_notes
 * @property string $tax_id
 * @property string $country
 * @property string $email
 * @property string $phone
 * @property string $region
 * @property string $provincy_country
 * @property string $regency
 * @property string $city
 * @property string $zip_code
 * @property string $address
 * @property string $bank_name
 * @property string $bank_account
 * @property string $bank_account_name
 * @property string $about
 * @property array $keywords
 * @property string $industry_type
 * @property string $logo_path
 * @property array $hq_addresses
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class Company extends Model
{
    use HasFactory, HasUuids, Notifiable;

    protected $fillable = [
        'owner_id',
        'name',
        'type', // buyer, vendor
        'status', // starter, pending, approved, rejected
        'verification_tier', // basic, standard, verified
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
        'keywords',
        'industry_type',
        'logo_path',
        'hq_addresses',
    ];

    protected $casts = [
        'keywords' => 'array',
        'hq_addresses' => 'array',
    ];

    protected $appends = ['logo_url', 'profile_completeness'];

    /**
     * Get the formatted tax ID.
     */
    public function getFormattedTaxIdAttribute(): string
    {
        return TaxIdFormatter::format($this->tax_id, $this->country ?? 'ID');
    }

    /**
     * Get the company logo URL.
     */
    public function getLogoUrlAttribute(): ?string
    {
        if (!$this->logo_path) {
            return null;
        }
        /** @var \Illuminate\Filesystem\FilesystemAdapter $storage */
        $storage = \Illuminate\Support\Facades\Storage::disk(config('filesystems.default'));
        return $storage->url($this->logo_path);
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    /**
     * Get the owner of this company.
     */
    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
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

    public function historicalPos()
    {
        return $this->hasMany(\App\Domain\Order\Models\PurchaseOrder::class, 'buyer_company_id')->where('is_historical', true);
    }

    public function documents()
    {
        return $this->hasMany(CompanyDocument::class);
    }

    public function subscriptions()
    {
        return $this->hasMany(\App\Domain\Subscription\Models\CompanySubscription::class);
    }

    /**
     * Check whether this company has been approved by admin and can transact.
     * 'starter' and 'pending' companies can browse but not publish RFQs / submit bids.
     */
    public function isVerified(): bool
    {
        return $this->status === 'approved';
    }

    /**
     * Alias: can this company perform state-changing procurement actions?
     */
    public function canTransact(): bool
    {
        return $this->isVerified();
    }

    /**
     * Check if the company is at starter tier (registered without docs).
     */
    public function isStarter(): bool
    {
        return $this->status === 'starter';
    }

    /**
     * Calculate profile completeness percentage (0-100).
     */
    public function getProfileCompletenessAttribute(): int
    {
        $fields = [
            'tax_id', 'email', 'phone', 'address',
            'bank_name', 'bank_account', 'industry_type',
        ];
        $filled = collect($fields)->filter(fn ($f) => ! empty($this->$f))->count();
        $docScore = $this->documents()->count() > 0 ? 1 : 0;
        $total = count($fields) + 1;
        return (int) round((($filled + $docScore) / $total) * 100);
    }
}
