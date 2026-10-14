<?php

namespace App\Domain\CrowdBuy\Http\Controllers;

use App\Domain\CrowdBuy\Models\CrowdBuyCampaign;
use App\Domain\CrowdBuy\Repositories\CrowdBuyRepositoryInterface;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicCampaignController extends Controller
{
    public function __construct(
        protected CrowdBuyRepositoryInterface $repository
    ) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->query('per_page', 12);
        $filters = $request->only(['search', 'category', 'min_price', 'max_price', 'sort', 'supplier_id']);

        $campaigns = $this->repository->getPublicCampaigns($filters, $perPage);

        return response()->json($campaigns);
    }

    public function show(string $idOrCode): JsonResponse
    {
        $campaign = $this->repository->findCampaignByIdOrCode($idOrCode);

        if (!$campaign) {
            return response()->json(['message' => 'Campaign Crowd Buy tidak ditemukan.'], 404);
        }

        // Additional aggregated metrics
        $participantsCount = $campaign->orders()
            ->whereIn('status', ['paid', 'processing', 'shipped', 'delivered'])
            ->distinct('buyer_company_id')
            ->count('buyer_company_id');

        return response()->json([
            'campaign' => $campaign,
            'participants_count' => $participantsCount,
        ]);
    }

    public function categories(): JsonResponse
    {
        $categories = CrowdBuyCampaign::whereIn('status', ['active', 'scheduled', 'successful', 'processing'])
            ->distinct()
            ->pluck('category')
            ->filter()
            ->values();

        return response()->json($categories);
    }
}
