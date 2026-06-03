<?php

namespace App\Domain\Access\Actions;

use App\Domain\Access\Models\Role;
use Illuminate\Database\Eloquent\Model;

class AssignRoleAction
{
    /**
     * Assign a role to a model (User or Admin).
     */
    public function execute(Model $model, string $roleSlug): void
    {
        if (method_exists($model, 'assignRole')) {
            $model->assignRole($roleSlug);
        }
    }
}
