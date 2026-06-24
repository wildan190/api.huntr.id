<?php

namespace App\Providers;

use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Support\ServiceProvider;
use Laravel\Horizon\Horizon;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Scramble::afterOpenApiGenerated(function (OpenApi $openApi) {
            $openApi->secure(
                SecurityScheme::http('bearer')
            );
        });

        if (app()->environment('production')) {
            \URL::forceScheme('https');
        }
    }

    protected function gate()
    {
        Horizon::auth(function ($request) {
            $allowedIps = [
                '36.69.86.104', // IP Publik Mac Anda saat ini
            ];

            return in_array($request->ip(), $allowedIps);
        });
    }
}
