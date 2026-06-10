<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
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
                // User does not exist, logout
                auth()->logout();
                
                // Always return JSON for API routes (started with /api)
                if ($request->is('api/*') || $request->expectsJson()) {
                    return response()->json([
                        'message' => 'User not found. Please log in again.'
                    ], 401);
                }
                
                // For web requests, redirect to login page
                return redirect('/login');
            }
        }

        return $next($request);
    }
}
