<?php

namespace App\Domain\Auth\Http\Controllers;

use App\Domain\Auth\Models\User;
use App\Domain\Access\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class RoleSwitchController
{
    /**
     * Switch the authenticated user's role (only in local environment).
     */
    public function switch(Request $request): JsonResponse
    {
        // Only allow this in local environment
        if (app()->environment() !== 'local') {
            return response()->json([
                'message' => 'Role switching is only allowed in local environment'
            ], 403);
        }

        $request->validate([
            'role' => 'required|string|in:super-admin,admin,manager,staff,buyer,finance'
        ]);

        /** @var User $user */
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'User not authenticated'], 401);
        }

        // Switch to new role (this will remove old roles and add new one)
        $role = Role::where('slug', $request->role)->first();
        
        if (!$role) {
            // Try to seed roles automatically
            Log::warning('Role not found, attempting to seed roles', [
                'requested_role' => $request->role,
            ]);
            
            try {
                \Artisan::call('db:seed', ['--class' => 'AccessControlSeeder']);
                
                // Try to find the role again
                $role = Role::where('slug', $request->role)->first();
                
                if (!$role) {
                    return response()->json([
                        'message' => 'Role not found even after seeding. Please run: php artisan db:seed --class=AccessControlSeeder',
                        'requested_role' => $request->role,
                    ], 404);
                }
                
                Log::info('Roles seeded successfully');
            } catch (\Exception $e) {
                Log::error('Failed to seed roles', [
                    'error' => $e->getMessage(),
                ]);
                
                return response()->json([
                    'message' => 'Role not found and auto-seeding failed. Please run manually: php artisan db:seed --class=AccessControlSeeder',
                    'error' => $e->getMessage(),
                ], 500);
            }
        }
        
        // Use sync to replace all roles with the new one
        // sync() handles morphToMany properly including model_type
        $user->roles()->sync([$role->id]);

        Log::info('User switched role locally', [
            'user_id' => $user->id,
            'new_role' => $request->role,
            'role_id' => $role->id,
        ]);

        // Force refresh from database
        $user->unsetRelation('roles');
        $user->load('roles');
        
        Log::info('Roles after switch', [
            'user_id' => $user->id,
            'roles_count' => $user->roles->count(),
            'roles' => $user->roles->pluck('slug')->toArray(),
        ]);

        return response()->json([
            'message' => 'Role switched successfully. Role will be active for all subsequent requests.',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $request->role,
                'roles' => $user->roles->pluck('slug')->toArray()
            ]
        ]);
    }
}
