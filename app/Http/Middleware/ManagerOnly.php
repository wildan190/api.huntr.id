<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Domain\Rfq\Models\Rfq;
use App\Domain\Proposal\Models\Proposal;
use App\Domain\Company\Models\Company;

/**
 * ManagerOnly Middleware
 * 
 * Ensures only users with 'manager' role or company owners can access certain routes.
 */
class ManagerOnly
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        
        if (!$user) {
            return response()->json([
                'message' => 'Authentication required.',
                'error' => 'User not authenticated'
            ], 401);
        }
        
        $company = null;
        
        // Try to get company from rfq route parameter first
        $rfqId = $request->route('rfq');
        if ($rfqId) {
            $rfq = Rfq::with('company')->find($rfqId->id ?? $rfqId);
            if ($rfq && $rfq->company) {
                $company = $rfq->company;
            }
        }
        
        // If no rfq, try proposal route parameter
        if (!$company) {
            $proposalId = $request->route('proposal');
            if ($proposalId) {
                $proposal = Proposal::with('rfq.company')->find($proposalId->id ?? $proposalId);
                if ($proposal && $proposal->rfq && $proposal->rfq->company) {
                    $company = $proposal->rfq->company;
                }
            }
        }
        
        // If still no company, try company_id from query or request body
        if (!$company) {
            $companyId = $request->query('company_id') ?? $request->input('company_id');
            if ($companyId) {
                $company = Company::find($companyId);
            }
        }
        
        // If no company found, use user's own company
        if (!$company && $user->company) {
            $company = $user->company;
        }
        
        if (!$company) {
            return response()->json([
                'message' => 'Company context required.',
                'error' => 'No company found for this request'
            ], 400);
        }
        
        // Check if user is owner or has manager/admin/super-admin role
        $isOwner = $company->owner_id === $user->id;
        $isAuthorized = $isOwner || $user->hasAnyRole(['super-admin', 'admin', 'manager']);
        
        if (!$isAuthorized) {
            return response()->json([
                'message' => 'Access denied. Manager role required.',
                'error' => 'Only managers or company owners can access this feature'
            ], 403);
        }
        
        return $next($request);
    }
}