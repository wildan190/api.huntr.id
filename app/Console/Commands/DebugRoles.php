<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Domain\Access\Models\Role;

class DebugRoles extends Command
{
    protected $signature = 'debug:roles';
    protected $description = 'Debug available roles in the system';

    public function handle()
    {
        $this->info('=== ROLE DEBUG ===');
        
        try {
            $roles = Role::all();
            
            if ($roles->isEmpty()) {
                $this->error('No roles found in database!');
                $this->info('Run: php artisan db:seed --class=AccessControlSeeder');
                return;
            }
            
            $this->info('Available roles:');
            foreach ($roles as $role) {
                $this->line("- {$role->slug} ({$role->name})");
            }
            
            // Test specific role
            $financeRole = Role::where('slug', 'finance')->first();
            if ($financeRole) {
                $this->info("\n✅ Finance role found: {$financeRole->name}");
            } else {
                $this->error("\n❌ Finance role NOT found!");
            }
            
        } catch (\Exception $e) {
            $this->error('Database error: ' . $e->getMessage());
        }
    }
}