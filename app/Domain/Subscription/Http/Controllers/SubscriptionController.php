<?php

namespace App\Domain\Subscription\Http\Controllers;

use App\Domain\Company\Models\Company;
use App\Domain\Subscription\Actions\GetSubscriptionSummaryAction;
use App\Domain\Subscription\Services\AdminEnterprisePricingService;
use App\Domain\Subscription\Services\SubscriptionAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubscriptionController extends \App\Http\Controllers\Controller
{
    public function plans(AdminEnterprisePricingService $pricing): JsonResponse
    {
        $configuration = $pricing->get();
        return response()->json([
            'plans' => $configuration['plans'],
            'version' => $configuration['version'],
            'addons' => $configuration['addons'],
            'vendor_free' => true,
            'checkout_enabled' => (bool) config('enterprise_subscription.checkout_enabled', false),
            'onboarding_business_days' => ['min' => 1, 'max' => 3],
        ]);
    }

    public function show(Request $request, Company $company, GetSubscriptionSummaryAction $action, SubscriptionAccessService $access): JsonResponse
    {
        $this->authorizeCompany($request, $company);

        return response()->json([
            'subscription' => $action->execute($company),
            'access' => $access->status($company),
        ]);
    }
    public function access(Request $request, Company $company, SubscriptionAccessService $access): JsonResponse
    {
        $this->authorizeCompany($request, $company);

        return response()->json(['access' => $access->status($company)]);
    }

    private function authorizeCompany(Request $request, Company $company): void
    {
        $user = $request->user();
        abort_unless((string) $company->owner_id === (string) $user->id || $company->users()->whereKey($user->id)->exists(), 403, 'Anda tidak memiliki akses ke subscription perusahaan ini.');
    }

}
