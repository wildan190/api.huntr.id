<?php

namespace App\Domain\Order\Actions;

use App\Domain\Order\Models\PurchaseOrder;
use App\Domain\Company\Models\Company;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Action to fetch and map purchase orders based on company type and search criteria.
 */
class GetPurchaseOrdersAction
{
    /**
     * Execute the action.
     *
     * @param array $params
     * @return array
     */
    public function execute(array $params): array
    {
        $companyId = $params['company_id'];
        $perPage   = $params['per_page'] ?? 10;
        $search    = $params['search'] ?? null;
        $type      = $params['type'] ?? 'all'; // all, operational, historical
        
        $company = Company::findOrFail($companyId);

        $query = PurchaseOrder::with([
            'historicalItems', 
            'invoices', 
            'deliveryOrders', 
            'basts',
            'rfq.items.catalogue', 
            'rfq.proposals' => function($q) {
                $q->where('status', 'accepted')->with(['items', 'acceptedNegotiation.items']);
            },
            'vendor',
            'buyer'
        ])->orderBy('created_at', 'desc');

        if ($company->type === 'buyer') {
            $query->where('buyer_company_id', $companyId);
        } else {
            $query->where('vendor_id', $companyId);
        }

        if ($type === 'operational') {
            $query->where('is_historical', false);
        } elseif ($type === 'historical') {
            $query->where('is_historical', true);
        }

        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('po_number', 'ilike', "%{$search}%")
                  ->orWhere('vendor_name', 'ilike', "%{$search}%")
                  ->orWhere('created_by', 'ilike', "%{$search}%")
                  ->orWhereHas('rfq', function ($rq) use ($search) {
                      $rq->where('title', 'ilike', "%{$search}%");
                  });
            });
        }

        $paginator = $query->paginate($perPage);
        $items = $paginator->items();

        // Manual eager loading for creator and approver to avoid UUID errors with historical names in Postgres
        $this->loadUsersForPos($items);

        return [
            'data'         => $this->mapItems($items),
            'current_page' => $paginator->currentPage(),
            'last_page'    => $paginator->lastPage(),
            'total'        => $paginator->total(),
            'per_page'     => $paginator->perPage(),
        ];
    }

    /**
     * Manual eager loading for creator and approver to avoid UUID errors with historical names.
     */
    private function loadUsersForPos(array $items): void
    {
        $userIds = collect($items)->flatMap(function ($po) {
            return [$po->created_by, $po->approved_by];
        })->filter(function ($id) {
            // Only collect valid UUIDs to avoid Postgres type errors
            return $id && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id);
        })->unique();

        if ($userIds->isEmpty()) {
            return;
        }

        $users = \App\Domain\Auth\Models\User::whereIn('id', $userIds->toArray())->get()->keyBy('id');

        foreach ($items as $po) {
            if ($po->created_by && $users->has($po->created_by)) {
                $po->setRelation('creator', $users->get($po->created_by));
            }
            if ($po->approved_by && $users->has($po->approved_by)) {
                $po->setRelation('approver', $users->get($po->approved_by));
            }
        }
    }

    /**
     * Map PO items to a standardized format for the frontend.
     */
    private function mapItems(array $items): array
    {
        return array_map(function ($po) {
            $mappedItems = collect();

            if ($po->is_historical) {
                $mappedItems = $po->historicalItems->map(function ($item) {
                    return [
                        'pr_reference_number' => $item->pr_reference_number,
                        'inventory_code'      => $item->inventory_code,
                        'inventory_name'      => $item->inventory_name,
                        'category'            => $item->category,
                        'uom'                 => $item->uom,
                        'qty'                 => $item->qty,
                        'unit_price'          => $item->unit_price,
                        'tax_amount'          => $item->tax_amount,
                        'total_amount'        => $item->total_amount,
                    ];
                });
            } else if ($po->rfq) {
                // Find the awarded proposal specifically
                $winningProposal = $po->rfq->proposals->where('status', 'accepted')->first() 
                    ?? $po->rfq->proposals->where('winner_status', 'approved')->first()
                    ?? $po->rfq->proposals->where('winner_status', 'awarded')->first()
                    ?? $po->rfq->proposals->first();

                $mappedItems = $po->rfq->items->map(function ($item) use ($winningProposal) {
                    $cat = $item->catalogue;
                    $proposalItem = $winningProposal ? $winningProposal->items->where('rfq_item_id', $item->id)->first() : null;
                    
                    // Check for negotiation
                    $negotiationItem = null;
                    if ($winningProposal && $winningProposal->relationLoaded('acceptedNegotiation') && $winningProposal->acceptedNegotiation) {
                        $negotiationItem = $winningProposal->acceptedNegotiation->items
                            ->filter(function($nItem) use ($proposalItem, $item) {
                                return $nItem->proposal_item_id === ($proposalItem?->id ?? null);
                            })
                            ->first();
                    }

                    $unitPrice = $negotiationItem ? $negotiationItem->negotiated_price : ($proposalItem ? $proposalItem->price_offer : ($cat?->price ?? 0));
                    $qty = $negotiationItem ? $negotiationItem->negotiated_qty : $item->qty;

                    return [
                        'pr_reference_number' => 'RFQ-' . $item->rfq_id,
                        'inventory_code'      => $cat?->item_code ?? 'N/A',
                        'inventory_name'      => $cat?->name ?? 'N/A',
                        'category'            => $cat?->category ?? 'N/A',
                        'uom'                 => $cat?->uom ?? 'Pc',
                        'qty'                 => $qty,
                        'unit_price'          => (float) $unitPrice,
                        'tax_amount'          => 0,
                        'total_amount'        => $qty * $unitPrice,
                    ];
                });

                $totalAmount = $mappedItems->sum('total_amount');
            }

            return [
                'id'                => $po->id,
                'po_number'         => $po->po_number,
                'vendor_name'       => $po->vendor_name ?? $po->vendor?->name ?? 'N/A',
                'buyer_name'        => $po->buyer?->name ?? 'N/A',
                'buyer_address'     => $po->buyer?->address ?? 'N/A',
                'department'        => $po->department ?? 'N/A',
                'currency'          => $po->currency ?? 'IDR',
                'purchase_category' => $po->purchase_category ?? 'N/A',
                'purchase_type'     => $po->purchase_type ?? 'N/A',
                'order_date'        => $po->order_date?->format('Y-m-d') ?? $po->created_at->format('Y-m-d'),
                'expected_receiving_date' => $po->expected_receiving_date?->format('Y-m-d'),
                'status'            => $po->status,
                'is_historical'     => $po->is_historical,
                'updated_at'        => $po->updated_at->toIso8601String(),
                'created_by'        => ($po->relationLoaded('creator') && $po->creator) ? $po->creator->name : ($po->created_by ?? 'N/A'),
                'approved_by'       => ($po->relationLoaded('approver') && $po->approver) ? $po->approver->name : ($po->approved_by ?? 'N/A'),
                'total_amount'      => $totalAmount ?? $po->total_amount,
                'items'             => $mappedItems,
                'delivery_orders'   => $po->deliveryOrders->map(function ($do) {
                    return [
                        'id' => $do->id,
                        'do_number' => $do->do_number,
                        'tracking_number' => $do->tracking_number,
                        'status' => $do->status,
                    ];
                }),
                'invoices'          => $po->invoices->map(function ($inv) {
                    return [
                        'id'     => $inv->id,
                        'type'   => $inv->type,
                        'amount' => $inv->amount,
                        'status' => $inv->status,
                        'date'   => $inv->created_at->format('Y-m-d'),
                    ];
                }),
                'basts'             => $po->basts->map(function ($bast) {
                    return [
                        'id'            => $bast->id,
                        'bast_number'   => $bast->bast_number,
                        'bast_date'     => $bast->bast_date?->format('Y-m-d'),
                        'status'        => $bast->status,
                        'handed_by_name' => $bast->handed_by_name,
                    ];
                }),
            ];
        }, $items);
    }
}
