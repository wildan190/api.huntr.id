<?php

namespace App\Domain\Subscription\Http\Controllers;

use App\Domain\Subscription\Http\Requests\UpdateEnterprisePricingRequest;
use App\Domain\Subscription\Services\AdminEnterprisePricingService;
use App\Domain\Subscription\Services\AdminSubscriptionMonitorService;
use App\Domain\Subscription\Services\SubscriptionPaymentService;
use App\Domain\Subscription\Models\SubscriptionCheckout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminEnterpriseController extends \App\Http\Controllers\Controller
{
    public function transactions(Request $request, AdminSubscriptionMonitorService $monitor): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'], 'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['nullable', Rule::in(['initiating', 'pending', 'unknown', 'paid', 'deny', 'cancel', 'expire', 'failure'])],
            'purchase_type' => ['nullable', Rule::in(['plan', 'addon', 'addons'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        if (! empty($filters['from']) && ! empty($filters['to']) && $filters['to'] < $filters['from']) {
            throw \Illuminate\Validation\ValidationException::withMessages(['to' => 'Tanggal akhir harus setelah tanggal awal.']);
        }
        return response()->json($monitor->transactions($filters));
    }

    public function pricing(AdminEnterprisePricingService $pricing, AdminSubscriptionMonitorService $monitor): JsonResponse
    {
        return response()->json($pricing->get() + ['audit' => $monitor->audit(), 'checkout_enabled' => (bool) config('enterprise_subscription.checkout_enabled', false)]);
    }

    public function updatePricing(UpdateEnterprisePricingRequest $request, AdminEnterprisePricingService $pricing): JsonResponse
    {
        return response()->json($pricing->update($request->validated(), (int) $request->attributes->get('admin_id')));
    }

    public function refresh(SubscriptionCheckout $checkout, SubscriptionPaymentService $payments): JsonResponse
    {
        $checkout = $payments->refresh($checkout);
        return response()->json(['status' => $checkout->status]);
    }
}
