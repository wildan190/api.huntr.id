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
            $this->info("   Fixed: {$results['fixed']}");
            if ($results['errors'] > 0) {
                $this->error("   Errors: {$results['errors']}");
            }
        }
        
        return 0;
    }
}