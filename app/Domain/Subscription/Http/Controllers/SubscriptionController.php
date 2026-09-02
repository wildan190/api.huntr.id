<?php

namespace App\Domain\Subscription\Http\Controllers;

use App\Domain\Company\Models\Company;
use App\Domain\Subscription\Actions\GetSubscriptionSummaryAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubscriptionController extends \App\Http\Controllers\Controller
{
    public function show(Request $request, Company $company, GetSubscriptionSummaryAction $action): JsonResponse
    {
        $user = $request->user();
        $isMember = $company->users()->whereKey($user->id)->exists();

        if ($company->owner_id !== $user->id && ! $isMember) {
            abort(403, 'Anda tidak memiliki akses ke subscription perusahaan ini.');
        }

        return response()->json([
            'subscription' => $action->execute($company),
        ]);
    }
}
