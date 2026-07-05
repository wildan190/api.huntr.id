<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Domain\Auth\Models\User;
use App\Domain\Access\Models\Role;

class DebugUserRole extends Command
{
    protected $signature = 'debug:user-role {user_email}';
    protected $description = 'Debug user role assignment';

    public function handle()
    {
        $email = $this->argument('user_email');
        
        $user = User::where('email', $email)->first();
        if (!$user) {
            $this->error("User not found: {$email}");
            return;
        }
        
        $this->info("=== USER DEBUG ===");
        $this->line("ID: {$user->id}");
        $this->line("Email: {$user->email}");
        $this->line("Name: {$user->name}");
        
        // Check current roles
        $roles = $user->roles;
        $this->info("\nCurrent roles:");
        if ($roles->isEmpty()) {
            $this->warn("No roles assigned");
        } else {
            foreach ($roles as $role) {
                $this->line("- {$role->slug} ({$role->name})");
            }
        }
        
        // Check role accessor
        $roleAccessor = $user->role;
        $this->line("Role accessor result: " . ($roleAccessor ?: 'null'));
        
        // Test assigning finance role
        $this->info("\n=== TESTING FINANCE ROLE ASSIGNMENT ===");
        try {
            $financeRole = Role::where('slug', 'finance')->first();
            if (!$financeRole) {
                $this->error("Finance role not found in database!");
                return;
            }
            
            $this->info("Finance role found: {$financeRole->name}");
            
            // Assign role
            $user->assignRole('finance');
            $user->refresh();
            
            $this->info("Role assigned successfully!");
            $this->line("New role: " . $user->role);
            
            // Check roles again
            $newRoles = $user->roles;
            $this->info("Updated roles:");
            foreach ($newRoles as $role) {
                $this->line("- {$role->slug} ({$role->name})");
            }
            
        } catch (\Exception $e) {
            $this->error("Failed to assign role: " . $e->getMessage());
        }
    }
}