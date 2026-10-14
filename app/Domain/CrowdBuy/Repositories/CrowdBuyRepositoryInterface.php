<?php

namespace App\Domain\CrowdBuy\Repositories;

use App\Domain\CrowdBuy\Models\CrowdBuyCampaign;
use App\Domain\CrowdBuy\Models\CrowdBuyOrder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface CrowdBuyRepositoryInterface
{
    // Public / Buyer
    public function getPublicCampaigns(array $filters = [], int $perPage = 12): LengthAwarePaginator;
    public function findCampaignByIdOrCode(string $idOrCode): ?CrowdBuyCampaign;
    public function getBuyerOrders(string $buyerCompanyId, array $filters = [], int $perPage = 10): LengthAwarePaginator;
    public function getBuyerInitiatives(string $buyerCompanyId, array $filters = [], int $perPage = 10): LengthAwarePaginator;
    public function findOrderById(string $orderId): ?CrowdBuyOrder;

    // Supplier
    public function getSupplierCampaigns(string $supplierCompanyId, array $filters = [], int $perPage = 10): LengthAwarePaginator;
    public function getCollectiveRfqs(array $filters = [], int $perPage = 10): LengthAwarePaginator;
    public function getSupplierOrders(string $supplierCompanyId, array $filters = [], int $perPage = 15): LengthAwarePaginator;

    // Admin
    public function getAllCampaigns(array $filters = [], int $perPage = 15): LengthAwarePaginator;
    public function getAdminStats(): array;

    // Mutations
    public function createCampaign(array $data): CrowdBuyCampaign;
    public function updateCampaign(CrowdBuyCampaign $campaign, array $data): CrowdBuyCampaign;
    public function createOrder(array $data): CrowdBuyOrder;
    public function updateOrder(CrowdBuyOrder $order, array $data): CrowdBuyOrder;
    public function recordHistory(CrowdBuyCampaign $campaign, ?string $actorId, ?string $fromStatus, string $toStatus, ?string $notes = null, ?array $metadata = null): void;
}
