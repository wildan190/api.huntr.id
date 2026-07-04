<?php

namespace App\Console\Commands;

use App\Domain\Auth\Models\User;
use App\Domain\Company\Models\Company;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixCompanyRoleInconsistencies extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'company:fix-role-inconsistencies {--dry-run : Show what would be changed without making actual changes}';

    /**
     * The description of the console command.
     *
     * @var string
     */
    protected $description = 'Fix role inconsistencies where users have roles that don\'t match their company type';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $dryRun = $this->option('dry-run');
        
        if ($dryRun) {
            $this->info('Running in DRY RUN mode - no changes will be made');
        }

        $buyerRoles = ['buyer', 'manager', 'finance'];
        $vendorRoles = ['admin', 'manager', 'finance'];
        
        $inconsistencies = collect();

        // Find users with roles that don't match their company type
        $users = User::with(['roles', 'company'])
            ->whereHas('company')
            ->whereHas('roles')
            ->get();

        foreach ($users as $user) {
            $company = $user->company;
            if (!$company) continue;

            $userRole = $user->role; // Using the accessor
            if (!$userRole) continue;

            $isInconsistent = false;
            $suggestedRole = null;

            if ($company->type === 'buyer' && !in_array($userRole, $buyerRoles)) {
                $isInconsistent = true;
                // Map roles to appropriate buyer roles
                $suggestedRole = match($userRole) {
                    'admin' => 'buyer', // admin -> buyer for buyer companies
                    default => 'buyer'
                };
            } elseif ($company->type === 'vendor' && !in_array($userRole, $vendorRoles)) {
                $isInconsistent = true;
                // Map roles to appropriate vendor roles  
                $suggestedRole = match($userRole) {
                    'buyer' => 'admin', // buyer -> admin for vendor companies
                    default => 'admin'
                };
            }

            if ($isInconsistent) {
                $inconsistencies->push([
                    'user_id' => $user->id,
                    'user_name' => $user->name,
                    'user_email' => $user->email,
                    'company_id' => $company->id,
                    'company_name' => $company->name,
                    'company_type' => $company->type,
                    'current_role' => $userRole,
                    'suggested_role' => $suggestedRole,
                ]);
            }
        }

        if ($inconsistencies->isEmpty()) {
            $this->info('No role inconsistencies found!');
            return 0;
        }

        $this->info("Found {$inconsistencies->count()} role inconsistencies:");
        
        $headers = ['User', 'Email', 'Company', 'Company Type', 'Current Role', 'Suggested Role'];
        $rows = $inconsistencies->map(function ($item) {
            return [
                $item['user_name'],
                $item['user_email'],
                $item['company_name'],
                $item['company_type'],
                $item['current_role'],
                $item['suggested_role'],
            ];
        })->toArray();
        
        $this->table($headers, $rows);

        if ($dryRun) {
            $this->info('This was a dry run. Use the command without --dry-run to apply changes.');
            return 0;
        }

        if (!$this->confirm('Do you want to fix these inconsistencies?')) {
            $this->info('Operation cancelled.');
            return 0;
        }

        $fixed = 0;
        
        foreach ($inconsistencies as $item) {
            try {
                $user = User::find($item['user_id']);
                if ($user) {
                    // Remove current role and assign new one
                    DB::transaction(function () use ($user, $item) {
                        $user->removeRole($item['current_role']);
                        $user->assignRole($item['suggested_role']);
                    });
                    $fixed++;
                    $this->info("Fixed: {$item['user_name']} ({$item['user_email']}) - {$item['current_role']} → {$item['suggested_role']}");
                }
            } catch (\Exception $e) {
                $this->error("Failed to fix {$item['user_name']}: {$e->getMessage()}");
            }
        }

        $this->info("Successfully fixed {$fixed} role inconsistencies!");
        return 0;
    }
}