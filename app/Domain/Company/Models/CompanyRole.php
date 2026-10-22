<?php

namespace App\Domain\Company\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class CompanyRole extends Model
{
    use HasUuids;

    protected $fillable = ['company_id', 'slug', 'name', 'description', 'is_system'];
    protected $casts = ['is_system' => 'boolean'];

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function permissions(): BelongsToMany { return $this->belongsToMany(CompanyPermission::class, 'company_role_permissions', 'company_role_id', 'company_permission_id'); }
}
