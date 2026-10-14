<?php

namespace App\Domain\CrowdBuy\Http\Controllers;

use App\Domain\CrowdBuy\Actions\CreateCampaignAction;
use App\Domain\CrowdBuy\Actions\FulfillCrowdBuyOrderAction;
use App\Domain\CrowdBuy\Actions\SubmitCampaignAction;
use App\Domain\CrowdBuy\Actions\UpdateCampaignAction;
use App\Domain\CrowdBuy\Http\Requests\FulfillOrderRequest;
use App\Domain\CrowdBuy\Http\Requests\StoreCampaignRequest;
use App\Domain\CrowdBuy\Http\Requests\UpdateCampaignRequest;
use App\Domain\CrowdBuy\Models\CrowdBuyCampaign;
use App\Domain\CrowdBuy\Models\CrowdBuyOrder;
use App\Domain\CrowdBuy\Repositories\CrowdBuyRepositoryInterface;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupplierCampaignController extends Controller
{
    public function __construct(
        protected CrowdBuyRepositoryInterface $repository
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user->company_id) {
            return response()->json(['message' => 'Perusahaan supplier tidak ditemukan.'], 400);
        }

        $perPage = (int) $request->query('per_page', 10);
        $filters = $request->only(['status', 'search']);

        $campaigns = $this->repository->getSupplierCampaigns($user->company_id, $filters, $perPage);

        return response()->json($campaigns);
    }

    public function store(StoreCampaignRequest $request, CreateCampaignAction $action): JsonResponse
    {
        $user = $request->user();

        try {
            $campaign = $action->execute($user, $request->validated());

            return response()->json([
                'message' => 'Draft campaign Crowd Buy berhasil dibuat.',
                'campaign' => $campaign,
            ], 201);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function show(Request $request, string $campaignId): JsonResponse
    {
        $user = $request->user();
        $campaign = $this->repository->findCampaignByIdOrCode($campaignId);

        if (!$campaign) {
            return response()->json(['message' => 'Campaign tidak ditemukan.'], 404);
        }

        if ($campaign->supplier_company_id !== $user->company_id && !$user->hasRole('admin')) {
            return response()->json(['message' => 'Anda tidak memiliki akses ke campaign ini.'], 403);
        }

        return response()->json(['campaign' => $campaign]);
    }

    public function update(UpdateCampaignRequest $request, string $campaignId, UpdateCampaignAction $action): JsonResponse
    {
        $user = $request->user();
        $campaign = CrowdBuyCampaign::findOrFail($campaignId);

        try {
            $updated = $action->execute($campaign, $user, $request->validated());

            return response()->json([
                'message' => 'Campaign berhasil diperbarui.',
                'campaign' => $updated,
            ]);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function submit(Request $request, string $campaignId, SubmitCampaignAction $action): JsonResponse
    {
        $user = $request->user();
        $campaign = CrowdBuyCampaign::findOrFail($campaignId);

        try {
            $submitted = $action->execute($campaign, $user);

            return response()->json([
                'message' => 'Campaign berhasil diajukan untuk review admin.',
                'campaign' => $submitted,
            ]);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function orders(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user->company_id) {
            return response()->json(['message' => 'Perusahaan supplier tidak ditemukan.'], 400);
        }

        $perPage = (int) $request->query('per_page', 15);
        $filters = $request->only(['campaign_id', 'status']);

        $orders = $this->repository->getSupplierOrders($user->company_id, $filters, $perPage);

        return response()->json($orders);
    }

    public function fulfillOrder(FulfillOrderRequest $request, string $orderId, FulfillCrowdBuyOrderAction $action): JsonResponse
    {
        $user = $request->user();
        $order = CrowdBuyOrder::with('campaign')->findOrFail($orderId);

        try {
            $updated = $action->execute($order, $user, $request->validated());

            return response()->json([
                'message' => 'Status pemenuhan pesanan berhasil diperbarui.',
                'order' => $updated,
            ]);
        } catch (\DomainException | \InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function collectiveRfqs(Request $request): JsonResponse
    {
        $perPage = (int) $request->query('per_page', 10);
        $filters = $request->only(['category', 'search']);

        $rfqs = $this->repository->getCollectiveRfqs($filters, $perPage);

        return response()->json($rfqs);
    }

    public function submitBid(Request $request, string $campaignId, \App\Domain\CrowdBuy\Actions\SubmitSupplierBidAction $action): JsonResponse
    {
        $validated = $request->validate([
            'offered_unit_price' => 'required|numeric|min:0',
            'min_order_qty' => 'nullable|integer|min:1',
            'lead_time_days' => 'nullable|integer|min:1',
            'notes' => 'nullable|string|max:1000',
        ]);

        try {
            $bid = $action->execute($request->user(), $campaignId, $validated);

            return response()->json([
                'message' => 'Penawaran harga berhasil diajukan ke Collective RFQ!',
                'bid' => $bid,
            ], 201);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
