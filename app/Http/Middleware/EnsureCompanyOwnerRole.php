<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Domain\Company\Models\Company;

/**
 * EnsureCompanyOwnerRole Middleware
 * 
 * Tanggung jawab: Memastikan company owner memiliki role manager
 * tanpa mengubah database secara manual untuk mengatasi masalah
 * user existing yang tidak otomatis dapat manager role saat registrasi.
 */
class EnsureCompanyOwnerRole
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        
        if (!$user) {
            return $next($request);
        }

        // Check if user is a company owner but doesn't have manager role
        $ownedCompanies = Company::where('owner_id', $user->id)->get();
        
        foreach ($ownedCompanies as $company) {
            // If user owns a company but doesn't have manager role, assign it
            if (!$user->hasRole('manager')) {
                try {
                    $user->assignRole('manager');
                    
                    Log::info('Auto-assigned manager role to existing company owner', [
                        'user_id' => $user->id,
                        'user_email' => $user->email,
                        'company_id' => $company->id,
                        'company_name' => $company->name,
                        'reason' => 'runtime_fix_for_existing_users'
                    ]);
                    
                } catch (\Exception $e) {
                    Log::error('Failed to auto-assign manager role to company owner', [
                        'user_id' => $user->id,
                        'company_id' => $company->id,
                        'error' => $e->getMessage()
                    ]);
                }
                
                // Break after first assignment since user only needs one manager role
                break;
            }
        }

        return $next($request);
    }
}