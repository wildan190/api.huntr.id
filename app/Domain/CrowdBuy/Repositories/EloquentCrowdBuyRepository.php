<?php

namespace App\Domain\CrowdBuy\Repositories;

use App\Domain\CrowdBuy\Models\CrowdBuyCampaign;
use App\Domain\CrowdBuy\Models\CrowdBuyCampaignHistory;
use App\Domain\CrowdBuy\Models\CrowdBuyOrder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class EloquentCrowdBuyRepository implements CrowdBuyRepositoryInterface
{
    public function getPublicCampaigns(array $filters = [], int $perPage = 12): LengthAwarePaginator
    {
        $query = CrowdBuyCampaign::query()
            ->with([
                'supplier:id,name,logo_path,country,region',
                'initiatorCompany:id,name,logo_path,country,region',
                'priceTiers',
            ])
            ->whereIn('status', ['active', 'successful', 'processing', 'bidding'])
            ->where('starts_at', '<=', now());

        if (!empty($filters['search'])) {
            $search = '%' . strtolower($filters['search']) . '%';
            $query->where(function ($q) use ($search) {
                $q->whereRaw('LOWER(title) LIKE ?', [$search])
                  ->orWhereRaw('LOWER(description) LIKE ?', [$search])
                  ->orWhereRaw('LOWER(category) LIKE ?', [$search]);
            });
        }

        if (!empty($filters['category']) && $filters['category'] !== 'all') {
            $query->where('category', $filters['category']);
        }

        if (!empty($filters['campaign_type']) && $filters['campaign_type'] !== 'all') {
            $query->where('campaign_type', $filters['campaign_type']);
        }

        if (!empty($filters['creator_type']) && $filters['creator_type'] !== 'all') {
            $query->where('creator_type', $filters['creator_type']);
        }

        if (isset($filters['min_price'])) {
            $query->where('base_price', '>=', $filters['min_price']);
        }

        if (isset($filters['max_price'])) {
            $query->where('base_price', '<=', $filters['max_price']);
        }

        if (!empty($filters['supplier_id'])) {
            $query->where('supplier_company_id', $filters['supplier_id']);
        }

        // Sorting
        $sort = $filters['sort'] ?? 'ending_soonest';
        match ($sort) {
            'ending_soonest' => $query->orderBy('ends_at', 'asc'),
            'highest_progress' => $query->orderByRaw('(current_quantity::float / NULLIF(target_quantity, 0)) DESC'),
            'price_low_high' => $query->orderBy('base_price', 'asc'),
            'price_high_low' => $query->orderBy('base_price', 'desc'),
            'newest' => $query->latest('created_at'),
            default => $query->orderBy('ends_at', 'asc'),
        };

        return $query->paginate($perPage);
    }

    public function findCampaignByIdOrCode(string $idOrCode): ?CrowdBuyCampaign
    {
        return CrowdBuyCampaign::query()
            ->with([
                'supplier:id,name,logo_path,country,region,city,industry_type,status',
                'initiatorCompany:id,name,logo_path,country,region,city',
                'catalogue',
                'priceTiers',
                'bids.supplierCompany:id,name,logo_path',
                'bids.supplierUser:id,name',
                'histories.actor:id,name',
            ])
            ->where(function ($q) use ($idOrCode) {
                if (\Illuminate\Support\Str::isUuid($idOrCode)) {
                    $q->where('id', $idOrCode)
                      ->orWhere('code', $idOrCode)
                      ->orWhere('invite_code', $idOrCode);
                } else {
                    $q->where('code', $idOrCode)
                      ->orWhere('invite_code', $idOrCode);
                }
            })
            ->first();
    }

    public function getBuyerOrders(string $buyerCompanyId, array $filters = [], int $perPage = 10): LengthAwarePaginator
    {
        $query = CrowdBuyOrder::query()
            ->with([
                'campaign.supplier:id,name,logo_path',
                'user:id,name,email',
                'latestPayment',
            ])
            ->where('buyer_company_id', $buyerCompanyId);

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['search'])) {
            $search = '%' . strtolower($filters['search']) . '%';
            $query->where(function ($q) use ($search) {
                $q->whereRaw('LOWER(order_number) LIKE ?', [$search])
                  ->orWhereHas('campaign', function ($cq) use ($search) {
                      $cq->whereRaw('LOWER(title) LIKE ?', [$search]);
                  });
            });
        }

        return $query->latest('created_at')->paginate($perPage);
    }

    public function findOrderById(string $orderId): ?CrowdBuyOrder
    {
        return CrowdBuyOrder::query()
            ->with([
                'campaign.supplier:id,name,logo_path,phone,email,address',
                'buyerCompany:id,name,logo_path,phone,email,address',
                'user:id,name,email,whatsapp',
                'payments',
            ])
            ->find($orderId);
    }

    public function getBuyerInitiatives(string $buyerCompanyId, array $filters = [], int $perPage = 10): LengthAwarePaginator
    {
        $query = CrowdBuyCampaign::query()
            ->with([
                'supplier:id,name,logo_path',
                'priceTiers',
                'bids.supplierCompany:id,name,logo_path',
            ])
            ->withCount(['orders', 'bids'])
            ->where(function ($q) use ($buyerCompanyId) {
                $q->where('initiator_company_id', $buyerCompanyId)
                  ->orWhere(function ($sub) use ($buyerCompanyId) {
                      $sub->where('creator_type', 'buyer')
                          ->whereHas('creator', function ($uq) use ($buyerCompanyId) {
                              $uq->where('company_id', $buyerCompanyId);
                          });
                  });
            });

        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['campaign_type']) && $filters['campaign_type'] !== 'all') {
            $query->where('campaign_type', $filters['campaign_type']);
        }

        if (!empty($filters['search'])) {
            $search = '%' . strtolower($filters['search']) . '%';
            $query->where(function ($q) use ($search) {
                $q->whereRaw('LOWER(title) LIKE ?', [$search])
                  ->orWhereRaw('LOWER(code) LIKE ?', [$search]);
            });
        }

        return $query->latest('created_at')->paginate($perPage);
    }

    public function getCollectiveRfqs(array $filters = [], int $perPage = 10): LengthAwarePaginator
    {
        $query = CrowdBuyCampaign::query()
            ->with([
                'initiatorCompany:id,name,logo_path,country,region',
                'bids.supplierCompany:id,name,logo_path',
            ])
            ->withCount(['bids'])
            ->where('campaign_type', 'collective_rfq')
            ->whereIn('status', ['bidding', 'active']);

        if (!empty($filters['category']) && $filters['category'] !== 'all') {
            $query->where('category', $filters['category']);
        }

        if (!empty($filters['search'])) {
            $search = '%' . strtolower($filters['search']) . '%';
            $query->where(function ($q) use ($search) {
                $q->whereRaw('LOWER(title) LIKE ?', [$search])
                  ->orWhereRaw('LOWER(description) LIKE ?', [$search]);
            });
        }

        return $query->latest('created_at')->paginate($perPage);
    }

    public function getSupplierCampaigns(string $supplierCompanyId, array $filters = [], int $perPage = 10): LengthAwarePaginator
    {
        $query = CrowdBuyCampaign::query()
            ->with(['priceTiers'])
            ->withCount(['orders'])
            ->where('supplier_company_id', $supplierCompanyId);

        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['search'])) {
            $search = '%' . strtolower($filters['search']) . '%';
            $query->where(function ($q) use ($search) {
                $q->whereRaw('LOWER(title) LIKE ?', [$search])
                  ->orWhereRaw('LOWER(code) LIKE ?', [$search]);
            });
        }

        return $query->latest('created_at')->paginate($perPage);
    }

    public function getSupplierOrders(string $supplierCompanyId, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = CrowdBuyOrder::query()
            ->with([
                'campaign:id,title,code,unit',
                'buyerCompany:id,name,logo_path',
                'latestPayment',
            ])
            ->whereHas('campaign', function ($cq) use ($supplierCompanyId) {
                $cq->where('supplier_company_id', $supplierCompanyId);
            });

        if (!empty($filters['campaign_id'])) {
            $query->where('campaign_id', $filters['campaign_id']);
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->latest('created_at')->paginate($perPage);
    }

    public function getAllCampaigns(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = CrowdBuyCampaign::query()
            ->with(['supplier:id,name,logo_path,status', 'creator:id,name'])
            ->withCount(['orders']);

        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['search'])) {
            $search = '%' . strtolower($filters['search']) . '%';
            $query->where(function ($q) use ($search) {
                $q->whereRaw('LOWER(title) LIKE ?', [$search])
                  ->orWhereRaw('LOWER(code) LIKE ?', [$search])
                  ->orWhereHas('supplier', function ($sq) use ($search) {
                      $sq->whereRaw('LOWER(name) LIKE ?', [$search]);
                  });
            });
        }

        return $query->latest('created_at')->paginate($perPage);
    }

    public function getAdminStats(): array
    {
        $total = CrowdBuyCampaign::count();
        $pending = CrowdBuyCampaign::where('status', 'pending_review')->count();
        $active = CrowdBuyCampaign::where('status', 'active')->count();
        $successful = CrowdBuyCampaign::whereIn('status', ['successful', 'processing', 'completed'])->count();
        $failed = CrowdBuyCampaign::where('status', 'failed')->count();

        $totalGmv = CrowdBuyOrder::whereIn('status', ['paid', 'processing', 'shipped', 'delivered'])->sum('total_amount');
        $totalOrders = CrowdBuyOrder::whereIn('status', ['paid', 'processing', 'shipped', 'delivered'])->count();

        return [
            'total_campaigns' => $total,
            'pending_review' => $pending,
            'active_campaigns' => $active,
            'successful_campaigns' => $successful,
            'failed_campaigns' => $failed,
            'realized_gmv' => (float) $totalGmv,
            'valid_orders_count' => $totalOrders,
        ];
    }

    public function createCampaign(array $data): CrowdBuyCampaign
    {
        return DB::transaction(function () use ($data) {
            $priceTiers = $data['price_tiers'] ?? [];
            unset($data['price_tiers']);

            $campaign = CrowdBuyCampaign::create($data);

            if (!empty($priceTiers)) {
                foreach ($priceTiers as $tier) {
                    $campaign->priceTiers()->create([
                        'min_quantity' => $tier['min_quantity'],
                        'max_quantity' => $tier['max_quantity'] ?? null,
                        'unit_price' => $tier['unit_price'],
                    ]);
                }
            }

            return $campaign->fresh(['priceTiers']);
        });
    }

    public function updateCampaign(CrowdBuyCampaign $campaign, array $data): CrowdBuyCampaign
    {
        return DB::transaction(function () use ($campaign, $data) {
            if (isset($data['price_tiers'])) {
                $priceTiers = $data['price_tiers'];
                unset($data['price_tiers']);

                $campaign->priceTiers()->delete();
                foreach ($priceTiers as $tier) {
                    $campaign->priceTiers()->create([
                        'min_quantity' => $tier['min_quantity'],
                        'max_quantity' => $tier['max_quantity'] ?? null,
                        'unit_price' => $tier['unit_price'],
                    ]);
                }
            }

            $campaign->update($data);
            return $campaign->fresh(['priceTiers']);
        });
    }

    public function createOrder(array $data): CrowdBuyOrder
    {
        return CrowdBuyOrder::create($data);
    }

    public function updateOrder(CrowdBuyOrder $order, array $data): CrowdBuyOrder
    {
        $order->update($data);
        return $order->fresh();
    }

    public function recordHistory(CrowdBuyCampaign $campaign, ?string $actorId, ?string $fromStatus, string $toStatus, ?string $notes = null, ?array $metadata = null): void
    {
        CrowdBuyCampaignHistory::create([
            'campaign_id' => $campaign->id,
            'actor_id' => $actorId,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'notes' => $notes,
            'metadata' => $metadata,
        ]);
    }
}
