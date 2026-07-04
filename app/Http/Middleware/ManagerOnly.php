<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Domain\Rfq\Models\Rfq;

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
        
        // Get RFQ from route parameter to determine company context
        $rfqId = $request->route('rfq');
        
        if (!$rfqId) {
            return response()->json([
                'message' => 'RFQ context required.',
                'error' => 'No RFQ ID provided'
            ], 400);
        }
        
        $rfq = Rfq::with('company')->find($rfqId->id ?? $rfqId);
        
        if (!$rfq || !$rfq->company) {
            return response()->json([
                'message' => 'RFQ or company not found.',
                'error' => 'Invalid RFQ ID or company context'
            ], 404);
        }
        
        $company = $rfq->company;
        
        // Check if user is owner or has manager role
        $isOwner = $company->owner_id === $user->id;
        $isManager = $user->hasRole('manager');
        
        if (!$isManager && !$isOwner) {
            return response()->json([
                'message' => 'Access denied. Manager role required.',
                'error' => 'Only purchasing managers or company owners can approve/reject RFQs'
            ], 403);
        }
        
        return $next($request);
    }
}