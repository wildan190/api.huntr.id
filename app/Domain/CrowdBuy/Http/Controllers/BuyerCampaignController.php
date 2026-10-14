<?php

namespace App\Domain\CrowdBuy\Http\Controllers;

use App\Domain\CrowdBuy\Actions\AcceptSupplierBidAction;
use App\Domain\CrowdBuy\Actions\CreateBuyerCampaignAction;
use App\Domain\CrowdBuy\Models\CrowdBuyCampaign;
use App\Domain\CrowdBuy\Repositories\CrowdBuyRepositoryInterface;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BuyerCampaignController extends Controller
{
    public function __construct(
        protected CrowdBuyRepositoryInterface $repository
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user->company_id) {
            return response()->json(['message' => 'Perusahaan pembeli tidak ditemukan.'], 400);
        }

        $perPage = (int) $request->query('per_page', 10);
        $filters = $request->only(['status', 'search', 'campaign_type']);

        $initiatives = $this->repository->getBuyerInitiatives($user->company_id, $filters, $perPage);

        return response()->json($initiatives);
    }

    public function store(Request $request, CreateBuyerCampaignAction $action): JsonResponse
    {
        $validated = $request->validate([
            'campaign_type' => 'required|in:buyer_catalog,collective_rfq',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'category' => 'required|string|max:100',
            'unit' => 'required|string|max:50',
            'target_quantity' => 'required|integer|min:1',
            'ends_at' => 'required|date|after:now',

            // Buyer catalog specific (Opsi 1)
            'supplier_company_id' => 'required_if:campaign_type,buyer_catalog|nullable|uuid',
            'catalogue_id' => 'nullable|uuid',
            'base_price' => 'required_if:campaign_type,buyer_catalog|nullable|numeric|min:0',
            'price_tiers' => 'nullable|array',
            'price_tiers.*.min_quantity' => 'required_with:price_tiers|integer|min:1',
            'price_tiers.*.unit_price' => 'required_with:price_tiers|numeric|min:0',

            // Collective RFQ specific (Opsi 2)
            'target_price' => 'nullable|numeric|min:0',

            // Optional parameters
            'min_order_qty' => 'nullable|integer|min:1',
            'max_order_qty' => 'nullable|integer|min:1',
            'max_quantity' => 'nullable|integer|min:1',
            'lead_time_days' => 'nullable|integer|min:1',
            'delivery_terms' => 'nullable|string',
            'return_policy' => 'nullable|string',
            'image_path' => 'nullable|string',

            // Initial participation by the initiator
            'initial_quantity' => 'nullable|integer|min:0',
            'initial_shipping_address' => 'nullable|array',
        ]);

        try {
            $campaign = $action->execute($request->user(), $validated);

            $message = $validated['campaign_type'] === 'buyer_catalog'
                ? 'Group Buying berhasil diinisiasi! Bagikan link undangan ke rekan bisnis Anda.'
                : 'Permintaan Kolektif (Collective RFQ) berhasil dibuat! Supplier dapat mulai memberikan penawaran harga.';

            return response()->json([
                'message' => $message,
                'campaign' => $campaign,
                'invite_code' => $campaign->invite_code,
            ], 201);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function acceptBid(Request $request, string $campaignId, string $bidId, AcceptSupplierBidAction $action): JsonResponse
    {
        try {
            $campaign = $action->execute($request->user(), $campaignId, $bidId);

            return response()->json([
                'message' => 'Penawaran supplier berhasil diterima! Campaign kini aktif untuk proses pemesanan & pembayaran.',
                'campaign' => $campaign,
            ]);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function resolveInvite(string $inviteCode): JsonResponse
    {
        $campaign = $this->repository->findCampaignByIdOrCode($inviteCode);

        if (!$campaign) {
            return response()->json(['message' => 'Link undangan Group Buy tidak valid atau sudah kedaluwarsa.'], 404);
        }

        return response()->json([
            'campaign' => $campaign,
            'is_valid' => $campaign->is_active || $campaign->status === 'bidding',
        ]);
    }
}
