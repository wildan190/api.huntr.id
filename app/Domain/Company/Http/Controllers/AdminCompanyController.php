<?php

namespace App\Domain\Company\Http\Controllers;

use App\Domain\Company\Actions\AuditCompanyAction;
use App\Domain\Company\Http\Requests\AuditCompanyRequest;
use App\Domain\Company\Models\Company;
use App\Domain\Company\Repositories\CompanyRepositoryInterface;
use App\Domain\Order\Models\PurchaseOrder;
use App\Domain\Catalogue\Models\Catalogue;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Domain\Subscription\Actions\ActivateCompanySubscriptionAction;
use App\Domain\Subscription\Actions\GetSubscriptionSummaryAction;
use App\Domain\Subscription\Models\CompanySubscription;

class AdminCompanyController extends Controller
{
    public function getSubscription(Company $company, GetSubscriptionSummaryAction $action): JsonResponse
    {
        return response()->json(['subscription' => $action->execute($company)]);
    }

    public function activateSubscription(
        Request $request,
        Company $company,
        ActivateCompanySubscriptionAction $action,
    ): JsonResponse {
        $payload = $request->validate([
            'gmv_limit' => ['required', 'numeric', 'gt:0'],
            'overflow_strategy' => ['required', 'in:transaction_fee,renewal_required'],
            'payment_verified' => ['required', 'accepted'],
        ]);

        if ($company->type !== 'buyer') {
            return response()->json(['message' => 'Subscription GMV hanya berlaku untuk perusahaan buyer.'], 422);
        }

        $subscription = $action->execute(
            $company,
            (float) $payload['gmv_limit'],
            $payload['overflow_strategy'],
        );

        return response()->json([
            'message' => 'Subscription berhasil diaktifkan.',
            'subscription' => app(GetSubscriptionSummaryAction::class)->execute($company),
        ], 201);
    }

    public function listCompanies(Request $request, CompanyRepositoryInterface $repository): JsonResponse
    {
        $perPage = $request->query('per_page', 10);
        $filters = $request->only(['search', 'status']);

        $companies = $repository->getPaginated($filters, $perPage);
        $stats = $repository->getStats();

        return response()->json(array_merge($companies->toArray(), ['stats' => $stats]));
    }

    public function auditCompany(AuditCompanyRequest $request, AuditCompanyAction $action, Company $company): JsonResponse
    {
        $updatedCompany = $action->execute(
            $company,
            $request->input('action'),
            $request->input('notes')
        );

        return response()->json([
            'message' => 'Company status successfully updated.',
            'company' => $updatedCompany->load('documents'),
        ]);
    }

    /**
     * Return the imported data for a company (Historical POs for buyer, Catalogues for vendor).
     * Supports pagination via ?page and ?per_page query params.
     */
    public function getImportData(Request $request, Company $company): JsonResponse
    {
        $perPage = (int) $request->query('per_page', 20);
        $page    = (int) $request->query('page', 1);

        if ($company->type === 'buyer') {
            // Historical POs with their items count
            $query = PurchaseOrder::withCount(['historicalItems'])
                ->where('buyer_company_id', $company->id)
                ->where('is_historical', true)
                ->orderBy('order_date', 'desc')
                ->orderBy('created_at', 'desc');

            $paginated = $query->paginate($perPage, ['*'], 'page', $page);

            // Summary stats
            $totalPos    = PurchaseOrder::where('buyer_company_id', $company->id)->where('is_historical', true)->count();
            $totalItems  = \App\Domain\Order\Models\HistoricalPoItem::whereHas('purchaseOrder', fn($q) =>
                $q->where('buyer_company_id', $company->id)->where('is_historical', true)
            )->count();
            $totalAmount = PurchaseOrder::where('buyer_company_id', $company->id)
                ->where('is_historical', true)
                ->sum('total_amount');

            return response()->json([
                'type'        => 'buyer',
                'summary'     => [
                    'total_pos'    => $totalPos,
                    'total_items'  => $totalItems,
                    'total_amount' => $totalAmount,
                ],
                'data'       => $paginated,
            ]);
        }

        // Vendor — Catalogue items
        $query = Catalogue::where('company_id', $company->id)
            ->orderBy('created_at', 'desc');

        $paginated = $query->paginate($perPage, ['*'], 'page', $page);

        $totalItems      = Catalogue::where('company_id', $company->id)->count();
        $totalCategories = Catalogue::where('company_id', $company->id)
            ->whereNotNull('category')
            ->distinct('category')
            ->count('category');

        return response()->json([
            'type'    => 'vendor',
            'summary' => [
                'total_items'      => $totalItems,
                'total_categories' => $totalCategories,
            ],
            'data'    => $paginated,
        ]);
    }
}
