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
        // Jika user sudah authenticated, validasi bahwa user masih ada di database
        if ($request->user()) {
            // Cek apakah user masih ada di database
            if (!$request->user()->exists) {
                // User tidak ada, logout
                auth()->logout();
                
                // Jika request adalah API request, return JSON error
                if ($request->expectsJson()) {
                    return response()->json([
                        'message' => 'User tidak ditemukan. Silakan login kembali.'
                    ], 401);
                }
                
                // Redirect ke login
                return redirect('/login');
            }
        }

        return $next($request);
    }
}
