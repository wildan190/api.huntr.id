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
                
                // If request is an API request, return JSON error
                if ($request->expectsJson()) {
                    return response()->json([
                        'message' => 'User not found. Please log in again.'
                    ], 401);
                }
                
                // Redirect to login
                return redirect('/login');
            }
        }

        return $next($request);
    }
}
