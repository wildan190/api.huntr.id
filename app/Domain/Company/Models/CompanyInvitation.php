<?php

namespace App\Domain\Company\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use App\Domain\Auth\Models\User;

/**
 * @property string $id
 * @property string $company_id
 * @property string|null $email
 * @property string|null $whatsapp
 * @property string $role
 * @property string $token
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property string $created_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class CompanyInvitation extends Model
{
    use HasUuids;

    protected $fillable = [
        'company_id',
        'email',
        'whatsapp',
        'role',
        'token',
        'status',
        'expires_at',
        'created_by',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
