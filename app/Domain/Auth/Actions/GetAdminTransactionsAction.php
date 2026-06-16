<?php

namespace App\Domain\Auth\Actions;

use App\Domain\Order\Models\PurchaseOrder;
use Illuminate\Pagination\LengthAwarePaginator;

class GetAdminTransactionsAction
{
    public function execute(array $filters = [], int $perPage = 10): LengthAwarePaginator
    {
        $query = PurchaseOrder::with(['buyer', 'vendor', 'invoices', 'deliveryOrders'])
            ->orderBy('created_at', 'desc');

        if (!empty($filters['search'])) {
            $search = $filters['search'];

            $query->where(function ($q) use ($search) {
                $q->where('po_number', 'like', "%{$search}%")
                  ->orWhere('vendor_name', 'like', "%{$search}%")
                  ->orWhereHas('buyer', function ($b) use ($search) {
                      $b->where('name', 'like', "%{$search}%");
                  });
            });
        }

        return $query->paginate($perPage);
    }
}
