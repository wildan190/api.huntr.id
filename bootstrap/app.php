<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Exempt 2FA and API endpoints from CSRF validation (Bearer token auth)
        $middleware->validateCsrfTokens(except: [
            'user/two-factor-authentication',
            'user/two-factor-authentication/*',
            'user/two-factor-qr-code',
            'user/two-factor-recovery-codes',
            'user/confirmed-two-factor-authentication',
            'two-factor-challenge',
        ]);
        
        $middleware->append(\App\Http\Middleware\ValidateUserExists::class);
        $middleware->append(\App\Http\Middleware\CheckCompanyApproved::class);
        $middleware->append(\App\Http\Middleware\EnsureCompanyOwnerRole::class);
        $middleware->alias([
            'cors' => \App\Http\Middleware\HandleCors::class,
            'manager.only' => \App\Http\Middleware\ManagerOnly::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (Illuminate\Auth\AuthenticationException $e, Illuminate\Http\Request $request) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        });
    })->create();

// Don't register Sanctum routes - we're using bearer tokens instead
// Sanctum::routes();
