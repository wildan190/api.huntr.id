<?php

namespace App\Domain\Auth\Actions;

use App\Domain\Access\Models\Role;
use App\Domain\Auth\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class SwitchUserRoleAction
{
    public function execute(User $user, string $roleSlug): array
    {
        $role = $this->findRole($roleSlug);

        $user->roles()->sync([$role->id]);

        Log::info('User switched role locally', [
            'user_id' => $user->id,
            'new_role' => $roleSlug,
            'role_id' => $role->id,
        ]);

        $user->unsetRelation('roles');
        $user->load('roles');

        Log::info('Roles after switch', [
            'user_id' => $user->id,
            'roles_count' => $user->roles->count(),
            'roles' => $user->roles->pluck('slug')->toArray(),
        ]);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $roleSlug,
            'roles' => $user->roles->pluck('slug')->toArray(),
        ];
    }

    private function findRole(string $roleSlug): Role
    {
        $role = Role::where('slug', $roleSlug)->first();

        if ($role) {
            return $role;
        }

        Log::warning('Role not found, attempting to seed roles', [
            'requested_role' => $roleSlug,
        ]);

        try {
            Artisan::call('db:seed', ['--class' => 'AccessControlSeeder']);
        } catch (\Exception $e) {
            Log::error('Failed to seed roles', [
                'error' => $e->getMessage(),
            ]);

            throw new HttpException(
                500,
                'Role not found and auto-seeding failed. Please run manually: php artisan db:seed --class=AccessControlSeeder',
                $e
            );
        }

        $role = Role::where('slug', $roleSlug)->first();

        if (!$role) {
            throw new NotFoundHttpException(
                'Role not found even after seeding. Please run: php artisan db:seed --class=AccessControlSeeder'
            );
        }

        Log::info('Roles seeded successfully');

        return $role;
    }
}
