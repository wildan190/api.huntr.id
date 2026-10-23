<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // ── Trust Nginx reverse proxy: read X-Forwarded-Proto so Laravel knows it's HTTPS ──
        // This fixes Livewire/Pulse generating http:// URLs that cause CORS preflight redirects
        $middleware->trustProxies(at: '*');

        // Stamp every response with Octane/Swoole headers (visible in DevTools)
        $middleware->prepend(\App\Http\Middleware\AddOctaneHeaders::class);

        // Exempt 2FA, API, and Livewire endpoints from CSRF validation
        $middleware->validateCsrfTokens(except: [
            'user/two-factor-authentication',
            'user/two-factor-authentication/*',
            'user/two-factor-qr-code',
            'user/two-factor-recovery-codes',
            'user/confirmed-two-factor-authentication',
            'two-factor-challenge',
            'livewire/*',        // ← Livewire update endpoint (OPTIONS preflight)
            'livewire-*/*',      // ← versioned Livewire endpoint
        ]);

        $middleware->append(\App\Http\Middleware\ValidateUserExists::class);
        $middleware->append(\App\Http\Middleware\DemoModeDisabledModules::class);
        $middleware->append(\App\Http\Middleware\CheckCompanyApproved::class);
        $middleware->append(\App\Http\Middleware\EnsureCompanyOwnerRole::class);
        $middleware->alias([
            'cors' => \App\Http\Middleware\HandleCors::class,
            'manager.only' => \App\Http\Middleware\ManagerOnly::class,
            'admin.session' => \App\Http\Middleware\AuthenticateAdminSession::class,
        ]);
    })

    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (Illuminate\Auth\AuthenticationException $e, Illuminate\Http\Request $request) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        });
    })->create();

// Don't register Sanctum routes - we're using bearer tokens instead
// Sanctum::routes();
