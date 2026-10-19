<?php

namespace App\Domain\Subscription\Http\Controllers;

use App\Domain\Company\Models\Company;
use App\Domain\Subscription\Services\PricingAnnouncementService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PricingAnnouncementController extends Controller
{
    public function show(Request $request, Company $company, PricingAnnouncementService $service): JsonResponse
    {
        $this->authorizeCompany($request, $company);

        return response()->json(['announcement' => $service->current($company, $request->user())]);
    }

    public function acknowledge(Request $request, Company $company, string $announcement, PricingAnnouncementService $service): JsonResponse
    {
        $this->authorizeCompany($request, $company);
        abort_unless($service->acknowledge($company, $request->user(), $announcement), 404);

        return response()->json(['acknowledged' => true]);
    }

    private function authorizeCompany(Request $request, Company $company): void
    {
        $user = $request->user();
        abort_unless($company->owner_id === $user->id || $company->users()->whereKey($user->id)->exists(), 403);
    }
}
