<?php

namespace App\Domain\Access\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    protected $fillable = ['name', 'slug', 'description'];

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions');
    }

    public function users(): BelongsToMany
    {
        return $this->morphedByMany(\App\Domain\Auth\Models\User::class, 'model', 'model_roles', 'role_id', 'model_id');
    }

    public function admins(): BelongsToMany
    {
        return $this->morphedByMany(\App\Domain\Auth\Models\Admin::class, 'model', 'model_roles', 'role_id', 'model_id');
    }
}
