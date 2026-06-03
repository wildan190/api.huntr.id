<?php

namespace App\Domain\Access\Actions;

use App\Domain\Access\Models\Role;
use Illuminate\Support\Str;

class CreateRoleAction
{
    public function execute(array $data): Role
    {
        return Role::create([
            'name' => $data['name'],
            'slug' => $data['slug'] ?? Str::slug($data['name']),
            'description' => $data['description'] ?? null,
        ]);
    }
}
