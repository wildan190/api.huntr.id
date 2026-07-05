<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Domain\Company\Models\Company;
use App\Domain\Auth\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * FixCompanyOwnerRoles Command
 * 
 * Tanggung jawab: Memperbaiki role untuk company owner yang existing
 * tanpa mengubah struktur database.
 */
class FixCompanyOwnerRoles extends Command
{
    protected $signature = 'fix:company-owner-roles {--dry-run : Show what would be fixed without making changes}';
    protected $description = 'Fix existing company owners who do not have manager role';

    public function handle()
    {
        $isDryRun = $this->option('dry-run');
        
        $this->info('🔍 Scanning for company owners without manager role...');
        
        try {
            if ($isDryRun) {
                // Untuk dry run, kita cek manual
                $companies = Company::whereNotNull('owner_id')->with('owner')->get();
                
                $fixedCount = 0;
                $alreadyCorrectCount = 0;
                
                foreach ($companies as $company) {
                    if (!$company->owner) {
                        $this->warn("⚠️  Company '{$company->name}' (ID: {$company->id}) has owner_id but user not found");
                        continue;
                    }
                    
                    $owner = $company->owner;
                    
                    if ($owner->hasRole('manager')) {
                        $alreadyCorrectCount++;
                        $this->line("✅ {$owner->email} (Company: {$company->name}) already has manager role");
                    } else {
                        $this->warn("🔧 [DRY RUN] Would assign manager role to: {$owner->email} (Company: {$company->name})");
                        $fixedCount++;
                    }
                }
                
                $this->newLine();
                $this->info('📊 Summary:');
                $this->line("   Already correct: {$alreadyCorrectCount}");
                $this->warn("   Would fix: {$fixedCount}");
                $this->newLine();
                $this->info('💡 To apply changes, run: php artisan fix:company-owner-roles');
                
            } else {
                // Untuk actual fix, gunakan service
                $results = \App\Domain\Auth\Services\RoleFixService::fixAllCompanyOwners();
                
                foreach ($results['details'] as $detail) {
                    switch ($detail['status']) {
                        case 'already_correct':
                            $this->line("✅ {$detail['user_email']} (Company: {$detail['company_name']}) already has manager role");
                            break;
                        case 'fixed':
                            $this->info("✅ Assigned manager role to: {$detail['user_email']} (Company: {$detail['company_name']})");
                            break;
                        case 'error':
                            $message = $detail['message'] ?? 'Unknown error';
                            $email = $detail['user_email'] ?? 'Unknown user';
                            $this->error("❌ Failed to assign role to {$email}: {$message}");
                            break;
                    }
                }
                
                $this->newLine();
                $this->info('📊 Summary:');
                $this->line("   Already correct: {$results['already_correct']}");
                if (isset($results['fixed_roles']) && isset($results['fixed_assignments'])) {
                    $this->info("   Roles fixed: {$results['fixed_roles']}");
                    $this->info("   Company assignments fixed: {$results['fixed_assignments']}");
                    $totalFixed = $results['fixed_roles'] + $results['fixed_assignments'];
                    $this->info("   Total fixes: {$totalFixed}");
                } else {
                    // Fallback for old format
                    $fixed = $results['fixed'] ?? 0;
                    $this->info("   Fixed: {$fixed}");
                }
                if ($results['errors'] > 0) {
                    $this->error("   Errors: {$results['errors']}");
                }
            }
            
        } catch (\Exception $e) {
            $this->error("❌ Database connection error: {$e->getMessage()}");
            $this->newLine();
            $this->warn("💡 This command requires database access. Make sure the application is properly configured and database is running.");
            
            if (strpos($e->getMessage(), 'could not translate host name') !== false) {
                $this->warn("🐳 If running in Docker, make sure the containers are started:");
                $this->line("   docker-compose up -d");
            }
            
            return 1;
        }
        
        return 0;
    }
}