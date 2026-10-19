<?php

namespace App\Domain\CrowdBuy\Http\Controllers;

use App\Domain\CrowdBuy\Actions\EvaluateCampaignDeadlinesAction;
use App\Domain\CrowdBuy\Actions\ReviewCampaignAction;
use App\Domain\CrowdBuy\Http\Requests\ReviewCampaignRequest;
use App\Domain\CrowdBuy\Models\CrowdBuyCampaign;
use App\Domain\CrowdBuy\Repositories\CrowdBuyRepositoryInterface;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminCampaignController extends Controller
{
    public function __construct(
        protected CrowdBuyRepositoryInterface $repository
    ) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->query('per_page', 15);
        $filters = $request->only(['status', 'search']);

        $campaigns = $this->repository->getAllCampaigns($filters, $perPage);

        return response()->json($campaigns);
    }

    public function dashboard(): JsonResponse
    {
        $stats = $this->repository->getAdminStats();

        $recentCampaigns = CrowdBuyCampaign::with(['supplier:id,name,logo_path'])
            ->latest('created_at')
            ->limit(5)
            ->get();

        return response()->json([
            'stats' => $stats,
            'recent_campaigns' => $recentCampaigns,
        ]);
    }

    public function review(ReviewCampaignRequest $request, string $campaignId, ReviewCampaignAction $action): JsonResponse
    {
        $user = $request->user();
        $campaign = CrowdBuyCampaign::findOrFail($campaignId);
        $validated = $request->validated();

        try {
            $updated = $action->execute(
                $campaign,
                $user,
                $validated['action'],
                $validated['notes'] ?? null
            );

            return response()->json([
                'message' => 'Status review campaign berhasil diperbarui.',
                'campaign' => $updated,
            ]);
        } catch (\DomainException | \InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function suspend(Request $request, string $campaignId): JsonResponse
    {
        $user = $request->user();
        $campaign = CrowdBuyCampaign::findOrFail($campaignId);

        $oldStatus = $campaign->status;
        $campaign->update([
            'status' => 'suspended',
            'review_notes' => $request->input('reason', 'Ditangguhkan oleh administrator.'),
        ]);

        $this->repository->recordHistory(
            $campaign,
            $user->id,
            $oldStatus,
            'suspended',
            $request->input('reason', 'Ditangguhkan oleh administrator.')
        );

        return response()->json([
            'message' => 'Campaign berhasil ditangguhkan.',
            'campaign' => $campaign,
        ]);
    }

    public function evaluate(EvaluateCampaignDeadlinesAction $action): JsonResponse
    {
        $results = $action->execute();

        return response()->json([
            'message' => 'Evaluasi deadline campaign selesai diproses.',
            'results' => $results,
        ]);
    }

    public function historicalDemands(Request $request, \App\Domain\CrowdBuy\Services\AutoDemandAggregationService $service): JsonResponse
    {
        $minFrequency = (int) $request->query('min_freq', 1);
        $limit = (int) $request->query('limit', 20);

        $demands = $service->analyzeDemands([
            'min_frequency' => $minFrequency,
            'limit' => $limit,
        ]);

        return response()->json([
            'total' => $demands->count(),
            'demands' => $demands,
        ]);
    }

    public function autoAggregate(Request $request, \App\Domain\CrowdBuy\Services\AutoDemandAggregationService $service): JsonResponse
    {
        $minFrequency = (int) $request->input('min_freq', 1);
        $limit = (int) $request->input('limit', 20);

        $result = $service->generateCampaigns([
            'min_frequency' => $minFrequency,
            'limit' => $limit,
        ], $request->user());

        return response()->json([
            'message' => 'Agregasi permintaan historis berhasil diproses.',
            'data' => $result,
        ]);
    }
}
