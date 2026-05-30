<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Domain\Company\Models\Company;
use Symfony\Component\HttpFoundation\Response;

class CheckCompanyApproved
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        $company = null;

        // 1. Check if route parameter has a Company model or company ID
        $routeCompany = $request->route('company');
        if ($routeCompany instanceof Company) {
            $company = $routeCompany;
        } elseif (is_numeric($routeCompany)) {
            $company = Company::find($routeCompany);
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

        // 3. Block all state-changing actions (POST, PUT, PATCH, DELETE) for pending companies
        if ($company && $company->status === 'pending' && !$request->isMethod('GET')) {
            return response()->json([
                'message' => 'Akun perusahaan Anda masih pending. Silakan tunggu persetujuan admin dulu!'
            ], 403);
        }

        return $next($request);
    }
}
