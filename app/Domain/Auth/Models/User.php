<?php

namespace App\Domain\Auth\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use App\Domain\Access\Traits\HasAccess;
use Illuminate\Support\Facades\Log;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable, HasApiTokens, HasUuids, HasAccess;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'whatsapp',
        'password',
        'company_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_recovery_codes',
        'two_factor_secret',
    ];

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Get the first role slug (for backward compatibility).
     * Auto-fixes missing roles for existing users.
     */
    public function getRoleAttribute(): ?string
    {
        $role = $this->roles()->first()?->slug;
        
        // Auto-fix users without roles (primarily for existing production users)
        if (!$role && $this->id && !$this->roleFixAttempted) {
            try {
                $this->roleFixAttempted = true; // Prevent infinite recursion
                $fixed = $this->ensureCompanyOwnerRole();
                
                if ($fixed) {
                    // Reload relationship to get fresh role data
                    $this->load('roles');
                    $role = $this->roles()->first()?->slug;
                }
            } catch (\Exception $e) {
                Log::warning('Auto role fix failed in getRoleAttribute', [
                    'user_id' => $this->id,
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        return $role;
    }

    /**
     * Prevent infinite recursion in role fix
     */
    protected $roleFixAttempted = false;

    /**
     * Get the companies associated with this user.
     */
    public function companies()
    {
        return $this->hasMany(\App\Domain\Company\Models\Company::class, 'owner_id');
    }

    /**
     * Get the company this user belongs to.
     */
    public function company()
    {
        return $this->belongsTo(\App\Domain\Company\Models\Company::class);
    }

    /**
     * Ensure company owner has manager role.
     * Fix untuk existing users yang belum punya manager role.
     */
    public function ensureCompanyOwnerRole(): bool
    {
        return \App\Domain\Auth\Services\RoleFixService::fixUserRole($this);
    }
}
