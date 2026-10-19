<?php

namespace App\Domain\Subscription\Http\Controllers;

use App\Domain\Company\Models\Company;
use App\Domain\Subscription\Http\Requests\CreateSubscriptionCheckoutRequest;
use App\Domain\Subscription\Models\SubscriptionCheckout;
use App\Domain\Subscription\Services\SubscriptionPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubscriptionCheckoutController extends \App\Http\Controllers\Controller
{
    public function store(CreateSubscriptionCheckoutRequest $request, Company $company, SubscriptionPaymentService $service): JsonResponse
    {
        $this->authorizeCompany($request, $company);
        $checkout = $service->create($company, $request->validated());

        return response()->json(['checkout' => $this->serializeCheckout($checkout)], 201);
    }

    public function show(Request $request, Company $company, SubscriptionCheckout $checkout, SubscriptionPaymentService $service): JsonResponse
    {
        $this->authorizeCompany($request, $company);
        abort_unless((string) $checkout->company_id === (string) $company->id, 404);

        return response()->json(['checkout' => $this->serializeCheckout($service->refresh($checkout))]);
    }

    public function current(Request $request, Company $company, SubscriptionPaymentService $service): JsonResponse
    {
        $this->authorizeCompany($request, $company);
        $checkout = SubscriptionCheckout::query()->where('company_id', $company->id)
            ->whereIn('status', ['initiating', 'pending', 'unknown'])->latest()->first();

        return response()->json(['checkout' => $checkout ? $this->serializeCheckout($service->refresh($checkout)) : null]);
    }

    private function authorizeCompany(Request $request, Company $company): void
    {
        $user = $request->user();
        $owner = (string) $company->owner_id === (string) $user->id;
        $manager = $company->users()->whereKey($user->id)->exists() && $user->hasRole('manager');
        abort_unless($owner || $manager, 403, 'Hanya owner atau manager perusahaan yang dapat mengelola subscription.');
    }

    private function serializeCheckout(SubscriptionCheckout $checkout): array
    {
        return $checkout->only(['id', 'order_id', 'enterprise_plan', 'billing_cycle', 'amount', 'payment_method', 'status', 'payment_info', 'purchase_type', 'addon_key', 'quantity', 'addons_snapshot', 'item_snapshot', 'created_at']);
    }
}
