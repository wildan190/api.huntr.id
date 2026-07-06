<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class ValidateUserExists
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
        // If user is already authenticated, validate that the user still exists in the database
        if ($request->user()) {
            // Check if user still exists in database
            if (!$request->user()->exists) {
                // User does not exist, return JSON response
                return response()->json([
                    'message' => 'User not found. Please log in again.'
                ], 401);
            }
            
            // Force fresh roles from database by unloading and reloading
            // This ensures we always have the latest role data, especially after role switches
            $request->user()->unsetRelation('roles');
            $request->user()->load('roles');
            
            Log::info('ValidateUserExists middleware - roles loaded', [
                'user_id' => $request->user()->id,
                'uri' => $request->getRequestUri(),
                'roles' => $request->user()->roles->pluck('slug')->toArray(),
            ]);
        }

        return $next($request);
    }
}
