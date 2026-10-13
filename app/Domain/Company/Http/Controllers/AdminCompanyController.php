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
use App\Domain\Admin\Models\AdminSetting;
use App\Domain\Subscription\Actions\GetSubscriptionSummaryAction;
use App\Domain\Subscription\Models\CompanySubscription;

class AdminCompanyController extends Controller
{
    public function getSubscription(Company $company, GetSubscriptionSummaryAction $action): JsonResponse
    {
        $summary = $action->execute($company);
        $savedDetails = AdminSetting::get('company_plan_' . $company->id);

        $globalSettings = [
            'allow_all_migration' => (bool) AdminSetting::get('subscription_allow_all_migration', true),
            'default_plan'        => (string) AdminSetting::get('subscription_default_plan', 'annual_upfront_po'),
            'fee_percent'         => (float) AdminSetting::get('subscription_fee_percent', 1.5),
            'ppn_percent'         => (float) AdminSetting::get('subscription_ppn_percent', 11),
            'pph23_percent'       => (float) AdminSetting::get('subscription_pph23_percent', 2),
            'saas_fee'            => (float) AdminSetting::get('subscription_saas_fee', 2500000),
            'saas_cycle'          => (string) AdminSetting::get('subscription_saas_cycle', 'monthly'),
            'per_po_rate'         => (float) AdminSetting::get('subscription_per_po_rate', 2.0),
        ];

        // Total historical PO 1 year
        $historicalPoTotal = (float) PurchaseOrder::where('buyer_company_id', $company->id)
            ->where('is_historical', true)
            ->sum('total_amount');
        $historicalPoCount = (int) PurchaseOrder::where('buyer_company_id', $company->id)
            ->where('is_historical', true)
            ->count();

        return response()->json([
            'subscription'       => $summary,
            'plan_details'       => $savedDetails ? (is_string($savedDetails) ? json_decode($savedDetails, true) : $savedDetails) : null,
            'global_settings'    => $globalSettings,
            'historical_summary' => [
                'total_amount' => $historicalPoTotal,
                'total_pos'    => $historicalPoCount,
            ],
        ]);
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

    /**
     * Update/switch company subscription scheme dynamically with customizable rates (fee %, PPN %, PPh 23 %).
     */
    public function updateCompanySubscriptionScheme(
        Request $request,
        Company $company,
        ActivateCompanySubscriptionAction $action,
    ): JsonResponse {
        $payload = $request->validate([
            'plan'               => ['required', 'in:annual_upfront_po,saas_subscription,per_po,gmv_subscription'],
            'fee_percent'        => ['nullable', 'numeric', 'min:0', 'max:100'],
            'ppn_percent'        => ['nullable', 'numeric', 'min:0', 'max:100'],
            'pph23_percent'      => ['nullable', 'numeric', 'min:0', 'max:100'],
            'saas_fee'           => ['nullable', 'numeric', 'min:0'],
            'saas_cycle'         => ['nullable', 'in:monthly,annual'],
            'gmv_limit'          => ['nullable', 'numeric', 'min:0'],
            'overflow_strategy'  => ['nullable', 'in:transaction_fee,renewal_required'],
            'payment_verified'   => ['nullable', 'boolean'],
            'use_historical_po'  => ['nullable', 'boolean'],
        ]);

        if ($company->type !== 'buyer') {
            return response()->json(['message' => 'Skema langganan saat ini hanya berlaku untuk perusahaan buyer.'], 422);
        }

        $plan = $payload['plan'];
        $feePercent    = isset($payload['fee_percent']) ? (float) $payload['fee_percent'] : (float) AdminSetting::get('subscription_fee_percent', 1.5);
        $ppnPercent    = isset($payload['ppn_percent']) ? (float) $payload['ppn_percent'] : (float) AdminSetting::get('subscription_ppn_percent', 11);
        $pph23Percent  = isset($payload['pph23_percent']) ? (float) $payload['pph23_percent'] : (float) AdminSetting::get('subscription_pph23_percent', 2);
        $overflow      = $payload['overflow_strategy'] ?? CompanySubscription::OVERFLOW_TRANSACTION_FEE;

        $histTotal = (float) PurchaseOrder::where('buyer_company_id', $company->id)
            ->where('is_historical', true)
            ->sum('total_amount');
        $histCount = (int) PurchaseOrder::where('buyer_company_id', $company->id)
            ->where('is_historical', true)
            ->count();

        $calculation = [];
        $subscription = null;

        if ($plan === 'annual_upfront_po' || $plan === 'gmv_subscription') {
            // Annual Pembayaran di Muka Sesuai Historical PO 1 Tahun
            $gmvLimit = !empty($payload['use_historical_po']) || empty($payload['gmv_limit'])
                ? ($histTotal > 0 ? $histTotal : 1_000_000_000)
                : (float) $payload['gmv_limit'];

            $grossUpfront = round($gmvLimit * ($feePercent / 100), 2);
            $ppnAmount    = round($grossUpfront * ($ppnPercent / 100), 2);
            $pph23Amount  = round($grossUpfront * ($pph23Percent / 100), 2);
            $netTotal     = round($grossUpfront + $ppnAmount - $pph23Amount, 2);

            $calculation = [
                'base_gmv'         => $gmvLimit,
                'historical_total' => $histTotal,
                'historical_count' => $histCount,
                'fee_percent'      => $feePercent,
                'gross_fee'        => $grossUpfront,
                'ppn_percent'      => $ppnPercent,
                'ppn_amount'       => $ppnAmount,
                'pph23_percent'    => $pph23Percent,
                'pph23_amount'     => $pph23Amount,
                'net_total'        => $netTotal,
            ];

            $subscription = $action->execute(
                $company,
                $gmvLimit,
                $overflow,
                now(),
                $plan,
                $grossUpfront
            );
        } elseif ($plan === 'saas_subscription') {
            // SaaS Subscription Flat Periodik
            $saasCycle = $payload['saas_cycle'] ?? 'monthly';
            $saasFee   = isset($payload['saas_fee']) ? (float) $payload['saas_fee'] : (float) AdminSetting::get('subscription_saas_fee', 2500000);

            $ppnAmount   = round($saasFee * ($ppnPercent / 100), 2);
            $pph23Amount = round($saasFee * ($pph23Percent / 100), 2);
            $netTotal    = round($saasFee + $ppnAmount - $pph23Amount, 2);

            $calculation = [
                'saas_fee'      => $saasFee,
                'saas_cycle'    => $saasCycle,
                'ppn_percent'   => $ppnPercent,
                'ppn_amount'    => $ppnAmount,
                'pph23_percent' => $pph23Percent,
                'pph23_amount'  => $pph23Amount,
                'net_total'     => $netTotal,
            ];

            $subscription = $action->execute(
                $company,
                999_999_999_999, // Uncapped quota for SaaS
                CompanySubscription::OVERFLOW_RENEWAL_REQUIRED,
                now(),
                'saas_subscription',
                $saasFee
            );
        } elseif ($plan === 'per_po') {
            // Pay per Transaction PO
            $calculation = [
                'fee_percent'   => $feePercent,
                'ppn_percent'   => $ppnPercent,
                'pph23_percent' => $pph23Percent,
                'admin_bank'    => 4400,
            ];

            $subscription = $action->execute(
                $company,
                0,
                CompanySubscription::OVERFLOW_TRANSACTION_FEE,
                now(),
                'per_po',
                0
            );
        }

        // Save customized scheme details to AdminSetting
        $planDetails = [
            'plan'         => $plan,
            'calculation'  => $calculation,
            'updated_at'   => now()->toIso8601String(),
        ];
        AdminSetting::set('company_plan_' . $company->id, json_encode($planDetails));

        return response()->json([
            'message'      => 'Skema biaya langganan berhasil diperbarui.',
            'subscription' => app(GetSubscriptionSummaryAction::class)->execute($company),
            'plan_details' => $planDetails,
        ], 200);
    }

    /**
     * Get global subscription and tax settings.
     */
    public function getSubscriptionSettings(): JsonResponse
    {
        return response()->json([
            'allow_all_migration' => (bool) AdminSetting::get('subscription_allow_all_migration', true),
            'default_plan'        => (string) AdminSetting::get('subscription_default_plan', 'annual_upfront_po'),
            'fee_percent'         => (float) AdminSetting::get('subscription_fee_percent', 1.5),
            'ppn_percent'         => (float) AdminSetting::get('subscription_ppn_percent', 11),
            'pph23_percent'       => (float) AdminSetting::get('subscription_pph23_percent', 2),
            'saas_fee'            => (float) AdminSetting::get('subscription_saas_fee', 2500000),
            'saas_cycle'          => (string) AdminSetting::get('subscription_saas_cycle', 'monthly'),
            'per_po_rate'         => (float) AdminSetting::get('subscription_per_po_rate', 2.0),
        ]);
    }

    /**
     * Update global subscription settings and tax rates (so code doesn't need to be modified on tax rate changes).
     */
    public function updateSubscriptionSettings(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'allow_all_migration' => ['nullable', 'boolean'],
            'default_plan'        => ['nullable', 'in:annual_upfront_po,saas_subscription,per_po,gmv_subscription'],
            'fee_percent'         => ['nullable', 'numeric', 'min:0', 'max:100'],
            'ppn_percent'         => ['nullable', 'numeric', 'min:0', 'max:100'],
            'pph23_percent'       => ['nullable', 'numeric', 'min:0', 'max:100'],
            'saas_fee'            => ['nullable', 'numeric', 'min:0'],
            'saas_cycle'          => ['nullable', 'in:monthly,annual'],
            'per_po_rate'         => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        if (array_key_exists('allow_all_migration', $payload)) {
            AdminSetting::set('subscription_allow_all_migration', (bool) $payload['allow_all_migration']);
        }
        if (isset($payload['default_plan'])) {
            AdminSetting::set('subscription_default_plan', $payload['default_plan']);
        }
        if (isset($payload['fee_percent'])) {
            AdminSetting::set('subscription_fee_percent', (float) $payload['fee_percent']);
        }
        if (isset($payload['ppn_percent'])) {
            AdminSetting::set('subscription_ppn_percent', (float) $payload['ppn_percent']);
        }
        if (isset($payload['pph23_percent'])) {
            AdminSetting::set('subscription_pph23_percent', (float) $payload['pph23_percent']);
        }
        if (isset($payload['saas_fee'])) {
            AdminSetting::set('subscription_saas_fee', (float) $payload['saas_fee']);
        }
        if (isset($payload['saas_cycle'])) {
            AdminSetting::set('subscription_saas_cycle', $payload['saas_cycle']);
        }
        if (isset($payload['per_po_rate'])) {
            AdminSetting::set('subscription_per_po_rate', (float) $payload['per_po_rate']);
        }

        return response()->json([
            'message'  => 'Pengaturan skema langganan dan pajak berhasil disimpan.',
            'settings' => [
                'allow_all_migration' => (bool) AdminSetting::get('subscription_allow_all_migration', true),
                'default_plan'        => (string) AdminSetting::get('subscription_default_plan', 'annual_upfront_po'),
                'fee_percent'         => (float) AdminSetting::get('subscription_fee_percent', 1.5),
                'ppn_percent'         => (float) AdminSetting::get('subscription_ppn_percent', 11),
                'pph23_percent'       => (float) AdminSetting::get('subscription_pph23_percent', 2),
                'saas_fee'            => (float) AdminSetting::get('subscription_saas_fee', 2500000),
                'saas_cycle'          => (string) AdminSetting::get('subscription_saas_cycle', 'monthly'),
                'per_po_rate'         => (float) AdminSetting::get('subscription_per_po_rate', 2.0),
            ],
        ]);
    }

    /**
     * Bulk apply scheme or grant migration rights to all buyer companies.
     */
    public function bulkApplySubscription(
        Request $request,
        ActivateCompanySubscriptionAction $action,
    ): JsonResponse {
        $payload = $request->validate([
            'plan'               => ['required', 'in:annual_upfront_po,saas_subscription,per_po'],
            'fee_percent'        => ['nullable', 'numeric', 'min:0', 'max:100'],
            'ppn_percent'        => ['nullable', 'numeric', 'min:0', 'max:100'],
            'pph23_percent'      => ['nullable', 'numeric', 'min:0', 'max:100'],
            'saas_fee'           => ['nullable', 'numeric', 'min:0'],
            'overflow_strategy'  => ['nullable', 'in:transaction_fee,renewal_required'],
        ]);

        $plan         = $payload['plan'];
        $feePercent   = isset($payload['fee_percent']) ? (float) $payload['fee_percent'] : (float) AdminSetting::get('subscription_fee_percent', 1.5);
        $ppnPercent   = isset($payload['ppn_percent']) ? (float) $payload['ppn_percent'] : (float) AdminSetting::get('subscription_ppn_percent', 11);
        $pph23Percent = isset($payload['pph23_percent']) ? (float) $payload['pph23_percent'] : (float) AdminSetting::get('subscription_pph23_percent', 2);
        $overflow     = $payload['overflow_strategy'] ?? CompanySubscription::OVERFLOW_TRANSACTION_FEE;

        $companies = Company::where('type', 'buyer')->get();
        $count = 0;

        foreach ($companies as $comp) {
            $histTotal = (float) PurchaseOrder::where('buyer_company_id', $comp->id)
                ->where('is_historical', true)
                ->sum('total_amount');

            if ($plan === 'annual_upfront_po') {
                $gmvLimit     = $histTotal > 0 ? $histTotal : 1_000_000_000;
                $grossUpfront = round($gmvLimit * ($feePercent / 100), 2);
                $action->execute($comp, $gmvLimit, $overflow, now(), 'annual_upfront_po', $grossUpfront);
            } elseif ($plan === 'saas_subscription') {
                $saasFee = isset($payload['saas_fee']) ? (float) $payload['saas_fee'] : (float) AdminSetting::get('subscription_saas_fee', 2500000);
                $action->execute($comp, 999_999_999_999, CompanySubscription::OVERFLOW_RENEWAL_REQUIRED, now(), 'saas_subscription', $saasFee);
            } elseif ($plan === 'per_po') {
                $action->execute($comp, 0, CompanySubscription::OVERFLOW_TRANSACTION_FEE, now(), 'per_po', 0);
            }

            AdminSetting::set('company_plan_' . $comp->id, json_encode([
                'plan'        => $plan,
                'fee_percent' => $feePercent,
                'ppn_percent' => $ppnPercent,
                'pph23_percent' => $pph23Percent,
                'applied_via' => 'bulk_apply',
                'updated_at'  => now()->toIso8601String(),
            ]));

            $count++;
        }

        return response()->json([
            'message' => "Skema {$plan} berhasil diterapkan ke {$count} perusahaan buyer.",
            'applied_count' => $count,
        ]);
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
