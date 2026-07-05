<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Domain\Access\Models\Role;
use App\Domain\Access\Models\Permission;

/**
 * EnsureManagerRoleExists Command
 * 
 * Tanggung jawab: Memastikan role 'manager' ada di database
 */
class EnsureManagerRoleExists extends Command
{
    protected $signature = 'ensure:manager-role';
    protected $description = 'Ensure manager role exists in the database';

    public function handle()
    {
        $this->info('🔍 Checking if manager role exists...');
        
        // Check if manager role exists
        $managerRole = Role::where('slug', 'manager')->first();
        
        if ($managerRole) {
            $this->info('✅ Manager role already exists:');
            $this->line("   ID: {$managerRole->id}");
            $this->line("   Slug: {$managerRole->slug}");
            $this->line("   Name: {$managerRole->name}");
            
            // Show permissions
            $permissions = $managerRole->permissions()->pluck('slug')->toArray();
            $this->line("   Permissions: " . (empty($permissions) ? 'None' : implode(', ', $permissions)));
            
        } else {
            $this->warn('❌ Manager role does not exist. Creating it...');
            
            try {
                $managerRole = Role::create([
                    'slug' => 'manager',
                    'name' => 'Manager',
                    'description' => 'Company Manager with full access to company features'
                ]);
                
                $this->info('✅ Manager role created successfully:');
                $this->line("   ID: {$managerRole->id}");
                $this->line("   Slug: {$managerRole->slug}");
                $this->line("   Name: {$managerRole->name}");
                
            } catch (\Exception $e) {
                $this->error("❌ Failed to create manager role: {$e->getMessage()}");
                return 1;
            }
        }
        
        // Show all existing roles for reference
        $this->newLine();
        $this->info('📋 All existing roles:');
        $allRoles = Role::all();
        foreach ($allRoles as $role) {
            $this->line("   - {$role->slug} ({$role->name})");
        }
        
        return 0;
    }
}