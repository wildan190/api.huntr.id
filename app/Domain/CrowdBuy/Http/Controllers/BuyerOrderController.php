<?php

namespace App\Domain\CrowdBuy\Http\Controllers;

use App\Domain\CrowdBuy\Actions\PlaceCrowdBuyOrderAction;
use App\Domain\CrowdBuy\Http\Requests\StoreCrowdBuyOrderRequest;
use App\Domain\CrowdBuy\Models\CrowdBuyOrder;
use App\Domain\CrowdBuy\Repositories\CrowdBuyRepositoryInterface;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BuyerOrderController extends Controller
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
        $filters = $request->only(['status', 'search']);

        $orders = $this->repository->getBuyerOrders($user->company_id, $filters, $perPage);

        return response()->json($orders);
    }

    public function show(Request $request, string $orderId): JsonResponse
    {
        $user = $request->user();
        $order = $this->repository->findOrderById($orderId);

        if (!$order) {
            return response()->json(['message' => 'Order tidak ditemukan.'], 404);
        }

        // Authorization check: only buyer or supplier or admin can view
        $isBuyer = $order->buyer_company_id === $user->company_id;
        $isSupplier = $order->campaign->supplier_company_id === $user->company_id;
        $isAdmin = $user->hasRole('admin');

        if (!$isBuyer && !$isSupplier && !$isAdmin) {
            return response()->json(['message' => 'Anda tidak memiliki akses ke order ini.'], 403);
        }

        return response()->json(['order' => $order]);
    }

    public function store(StoreCrowdBuyOrderRequest $request, string $campaignId, PlaceCrowdBuyOrderAction $action): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        try {
            $isMonthlyCommitment = (bool) ($validated['is_monthly_commitment'] ?? false);
            $monthlyCommitmentQty = isset($validated['monthly_commitment_qty'])
                ? (int) $validated['monthly_commitment_qty']
                : ($isMonthlyCommitment ? (int) $validated['quantity'] : null);
            $deliveryPoint = $validated['delivery_point'] ?? null;

            $order = $action->execute(
                $user,
                $campaignId,
                $validated['quantity'],
                $validated['shipping_address'],
                $validated['notes'] ?? null,
                $isMonthlyCommitment,
                $monthlyCommitmentQty,
                $deliveryPoint
            );

            return response()->json([
                'message' => 'Order Crowd Buy berhasil dibuat. Silakan lakukan pembayaran sebelum batas waktu.',
                'order' => $order->load(['campaign.supplier']),
            ], 201);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function cancel(Request $request, string $orderId): JsonResponse
    {
        $user = $request->user();
        $order = $this->repository->findOrderById($orderId);

        if (!$order) {
            return response()->json(['message' => 'Order tidak ditemukan.'], 404);
        }

        if ($order->buyer_company_id !== $user->company_id && !$user->hasRole('admin')) {
            return response()->json(['message' => 'Anda tidak berwenang membatalkan order ini.'], 403);
        }

        if ($order->status !== 'pending_payment') {
            return response()->json(['message' => 'Hanya order yang belum dibayar yang dapat dibatalkan langsung.'], 422);
        }

        $order->update([
            'status' => 'cancelled',
            'cancellation_reason' => $request->input('reason', 'Dibatalkan oleh pembeli.'),
        ]);

        return response()->json([
            'message' => 'Order berhasil dibatalkan.',
            'order' => $order,
        ]);
    }
}
