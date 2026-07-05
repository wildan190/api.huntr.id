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
        // Don't use statefulApi - we're using bearer tokens instead
        // $middleware->statefulApi();
        
        // No CSRF validation needed for bearer token auth
        // $middleware->validateCsrfTokens(except: [...]);
        
        $middleware->append(\App\Http\Middleware\ValidateUserExists::class);
        $middleware->append(\App\Http\Middleware\CheckCompanyApproved::class);
        $middleware->append(\App\Http\Middleware\EnsureCompanyOwnerRole::class);
        $middleware->alias([
            'cors' => \App\Http\Middleware\HandleCors::class,
            'manager.only' => \App\Http\Middleware\ManagerOnly::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();

// Don't register Sanctum routes - we're using bearer tokens instead
// Sanctum::routes();
