<?php

namespace App\Domain\Access\Traits;

use App\Domain\Access\Models\Role;
use App\Domain\Access\Models\Permission;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

trait HasAccess
{
    /**
     * Get all roles for the model.
     */
    public function roles(): BelongsToMany
    {
        return $this->morphToMany(Role::class, 'model', 'model_roles', 'model_id', 'role_id');
    }

    /**
     * Check if the model has a specific role.
     */
    public function hasRole(string $roleSlug): bool
    {
        return $this->roles()->where('slug', $roleSlug)->exists();
    }

    /**
     * Check if the model has any of the given roles.
     */
    public function hasAnyRole(array $roleSlugs): bool
    {
        return $this->roles()->whereIn('slug', $roleSlugs)->exists();
    }

    /**
     * Check if the model has a specific permission.
     */
    public function hasPermission(string $permissionSlug): bool
    {
        return $this->roles()->whereHas('permissions', function ($query) use ($permissionSlug) {
            $query->where('slug', $permissionSlug);
        })->exists();
    }

    /**
     * Assign a role to the model.
     */
    public function assignRole(string $roleSlug): void
    {
        $role = Role::where('slug', $roleSlug)->first();
        if (!$role) {
            throw new \Exception("Role '{$roleSlug}' not found.");
        }
        
        // Remove all existing roles first to ensure clean assignment
        $this->roles()->detach();
        
        // Attach the new role
        $this->roles()->attach($role->id);
        
        // Clear any cached role relationships
        $this->unsetRelation('roles');
    }

    /**
     * Remove a role from the model.
     */
    public function removeRole(string $roleSlug): void
    {
        $role = Role::where('slug', $roleSlug)->first();
        if ($role) {
            $this->roles()->detach($role->id);
        }
    }
}
