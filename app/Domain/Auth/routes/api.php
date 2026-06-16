<?php

use Illuminate\Support\Facades\Route;
use App\Domain\Auth\Http\Controllers\RoleSwitchController;

Route::middleware(['api', 'auth:api'])->group(function () {
    Route::post('/role-switch', [RoleSwitchController::class, 'switch']);
    
    // Debug endpoint to check current user roles
    Route::get('/debug-roles', function(\Illuminate\Http\Request $request) {
        $user = $request->user();
        
        // Force fresh from database
        $user->unsetRelation('roles');
        $user->load('roles');
        
        // Also query directly from database
        $rolesFromDb = \App\Domain\Access\Models\Role::whereHas('users', function($q) use ($user) {
            $q->where('model_id', $user->id);
        })->get();
        
        return response()->json([
            'user_id' => $user->id,
            'user_name' => $user->name,
            'user_email' => $user->email,
            'roles_from_relation' => $user->roles->map(fn($r) => [
                'id' => $r->id,
                'name' => $r->name,
                'slug' => $r->slug,
            ]),
            'roles_from_db' => $rolesFromDb->map(fn($r) => [
                'id' => $r->id,
                'name' => $r->name,
                'slug' => $r->slug,
            ]),
            'model_roles_table' => \DB::table('model_roles')
                ->where('model_id', $user->id)
                ->where('model_type', get_class($user))
                ->get(),
        ]);
    });
});
