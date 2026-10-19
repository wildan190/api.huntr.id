<?php

namespace App\Domain\Subscription\Http\Middleware;

use App\Domain\Company\Models\Company;
use App\Domain\Subscription\Services\SubscriptionAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSubscriptionCurrent
{
    public function __construct(private SubscriptionAccessService $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        $company = Company::find($request->input('company_id'));
        if ($company) {
            $user = $request->user();
            abort_unless($user && ((string) $company->owner_id === (string) $user->id || $company->users()->whereKey($user->id)->exists()), 403, 'Anda tidak memiliki akses ke perusahaan ini.');
            $this->access->assertAvailable($company);
        }

        return $next($request);
    }
}
