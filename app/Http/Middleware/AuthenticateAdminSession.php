<?php

namespace App\Http\Middleware;

use App\Domain\Admin\Models\Admin;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateAdminSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->header('X-Admin-Authorization');
        $adminId = $token ? Cache::get('admin_session:' . hash('sha256', $token)) : null;

        if (! $adminId || ! Admin::find($adminId)) {
            return response()->json(['message' => 'Admin authentication required.'], 401);
        }

        return $next($request);
    }
}
