<?php

namespace App\Providers;

use App\Domain\Auth\Actions\Fortify\CreateNewUser;
use App\Domain\Auth\Actions\Fortify\ResetUserPassword;
use App\Domain\Auth\Actions\Fortify\UpdateUserPassword;
use App\Domain\Auth\Actions\Fortify\UpdateUserProfileInformation;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use App\Domain\Auth\Models\User;
use App\Support\WhatsappNumber;
use Illuminate\Support\Facades\Hash;
use App\Domain\Auth\Services\AuthService;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(AuthService::class, function ($app) {
            return new AuthService();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(AuthService $authService): void
    {
        Fortify::authenticateUsing(function (Request $request) use ($authService) {
            return $authService->attempt(
                $request->input('login'),
                $request->input('password')
            );
        });

        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::redirectUserForTwoFactorAuthenticationUsing(RedirectIfTwoFactorAuthenticatable::class);

        RateLimiter::for('login', function (Request $request) use ($authService) {
            $throttleKey = Str::transliterate(Str::lower($request->input($authService->getFortifyUsername())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('passkeys', function (Request $request) {
            $credentialId = $request->input('credential.id');

            return Limit::perMinute(10)->by(
                ($credentialId ?: $request->session()->getId()).'|'.$request->ip()
            );
        });
    }
}
