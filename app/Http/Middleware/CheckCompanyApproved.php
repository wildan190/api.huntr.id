<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Domain\Company\Models\Company;
use Symfony\Component\HttpFoundation\Response;

class CheckCompanyApproved
{
    /**
     * Endpoints that are part of the onboarding flow and should not be blocked
     * even if the company is in a rejected state.
     */
    protected array $onboardingPaths = [
        'api/companies/verify-npwp',
        'api/companies/documents/upload',
        'api/companies/logo/upload',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        // Skip check for onboarding endpoints — users must be able to complete
        // onboarding steps regardless of their company's current approval status.
        $currentPath = trim($request->path(), '/');
        foreach ($this->onboardingPaths as $onboardingPath) {
            if ($currentPath === $onboardingPath) {
                return $next($request);
            }
        }

        $company = null;

        // 1. Extract company ID from URL path for routes like /api/companies/{id}
        $pathSegments = explode('/', trim($request->path(), '/'));
        
        if (count($pathSegments) >= 2 && $pathSegments[0] === 'api' && $pathSegments[1] === 'companies') {
            if (isset($pathSegments[2]) && is_numeric($pathSegments[2])) {
                $company = Company::find($pathSegments[2]);
            }
        }

        // 2. Check if company_id is provided in input/query/header
        if (!$company) {
            $companyId = $request->input('company_id') 
                ?? $request->query('company_id') 
                ?? $request->header('X-Company-Id');
            
            if ($companyId) {
                $company = Company::find($companyId);
            }
        }

        // 3. Block state-changing actions (POST, PUT, PATCH, DELETE) for REJECTED companies
        // Allow all operations for 'approved' and 'pending' companies
        if ($company && $company->status === 'rejected' && !$request->isMethod('GET')) {
            return response()->json([
                'message' => 'Akun perusahaan Anda telah ditolak. Hubungi admin untuk informasi lebih lanjut.'
            ], 403);
        }

        return $next($request);
    }
}
