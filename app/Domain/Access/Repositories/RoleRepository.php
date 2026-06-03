<?php

namespace App\Domain\Access\Repositories;

use App\Domain\Access\Models\Role;
use Illuminate\Support\Collection;

class RoleRepository
{
    public function getAll(): Collection
    {
        return Role::all();
    }

    public function findBySlug(string $slug): ?Role
    {
        return Role::where('slug', $slug)->first();
    }
}
