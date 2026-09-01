<?php

namespace App\Domain\Order\Actions;

use App\Domain\Order\Models\PurchaseOrder;
use App\Domain\Company\Models\Company;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Filesystem\FilesystemAdapter;

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
        $perPage = $params['per_page'] ?? 10;
        $search = $params['search'] ?? null;
        $type = $params['type'] ?? 'all'; // all, operational, historical

        $company = Company::findOrFail($companyId);

        // Start with base query — relations are added conditionally below
        $query = PurchaseOrder::query()->orderBy('created_at', 'desc');

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

        // Paginate first (count query), then conditionally eager load based on what's in the page
        $paginator = $query->paginate($perPage);
        $items = $paginator->items();

        if (!empty($items)) {
            $hasHistorical  = collect($items)->contains(fn($po) => $po->is_historical);
            $hasOperational = collect($items)->contains(fn($po) => !$po->is_historical);

            // Always load historical items + buyer info for historical POs
            // (buyer_name/address needed for print PO; vendor_name is stored as plain text)
            if ($hasHistorical) {
                $paginator->getCollection()
                    ->filter(fn($po) => $po->is_historical)
                    ->loadMissing(['historicalItems', 'buyer']);
            }

            // Only load heavy operational relations when there are operational POs on this page
            if ($hasOperational) {
                $paginator->getCollection()
                    ->filter(fn($po) => !$po->is_historical)
                    ->loadMissing([
                        'invoices',
                        'deliveryOrders',
                        'basts',
                        'efakturs',
                        'rfq.items.catalogue',
                        'rfq.proposals' => function ($q) {
                            $q->where('status', 'accepted')->with(['items', 'acceptedNegotiation.items']);
                        },
                        'vendor',
                        'buyer',
                    ]);
            }
        }

        $this->loadUsersForPos($items);

        return [
            'data' => $this->mapItems($items),
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'total' => $paginator->total(),
            'per_page' => $paginator->perPage(),
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
                        'id' => $item->id,
                        'pr_reference_number' => $item->pr_reference_number,
                        'inventory_code' => $item->inventory_code,
                        'inventory_name' => $item->inventory_name,
                        'category' => $item->category,
                        'uom' => $item->uom,
                        'qty' => $item->qty,
                        'unit_price' => $item->unit_price,
                        'tax_amount' => $item->tax_amount,
                        'total_amount' => $item->total_amount,
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
                            ->filter(function ($nItem) use ($proposalItem, $item) {
                                return $nItem->proposal_item_id === ($proposalItem?->id ?? null);
                            })
                            ->first();
                    }

                    $unitPrice = $negotiationItem ? $negotiationItem->negotiated_price : ($proposalItem ? $proposalItem->price_offer : ($cat?->price ?? 0));
                    $qty = $negotiationItem ? $negotiationItem->negotiated_qty : $item->qty;

                    return [
                        'id' => $item->id,
                        'pr_reference_number' => 'RFQ-' . $item->rfq_id,
                        'inventory_code' => $cat?->item_code ?? 'N/A',
                        'inventory_name' => $cat?->name ?? 'N/A',
                        'category' => $cat?->category ?? 'N/A',
                        'uom' => $cat?->uom ?? 'Pc',
                        'qty' => $qty,
                        'unit_price' => (float) $unitPrice,
                        'tax_amount' => 0,
                        'total_amount' => $qty * $unitPrice,
                    ];
                });

                $totalAmount = $mappedItems->sum('total_amount');
            }

            /** @var FilesystemAdapter $storageDisk */
            $storageDisk = Storage::disk(config('filesystems.default'));
            $buyerLogoUrl = null;
            if ($po->relationLoaded('buyer') && $po->buyer && $po->buyer->logo_path) {
                $buyerLogoUrl = $storageDisk->url($po->buyer->logo_path);
            }
            $vendorLogoUrl = null;
            if ($po->relationLoaded('vendor') && $po->vendor && $po->vendor->logo_path) {
                $vendorLogoUrl = $storageDisk->url($po->vendor->logo_path);
            }
            $paymentScheme = null;
            if ($po->relationLoaded('rfq') && $po->rfq) {
                $winningProposal = $po->rfq->proposals->where('status', 'accepted')->first()
                    ?? $po->rfq->proposals->where('winner_status', 'approved')->first()
                    ?? $po->rfq->proposals->first();
                if ($winningProposal && $winningProposal->relationLoaded('acceptedNegotiation') && $winningProposal->acceptedNegotiation && !empty($winningProposal->acceptedNegotiation->payment_scheme)) {
                    $paymentScheme = $winningProposal->acceptedNegotiation->payment_scheme;
                } else if ($winningProposal && !empty($winningProposal->payment_term)) {
                    $paymentScheme = $winningProposal->payment_term;
                }
            }
            $paymentScheme = $paymentScheme ?? ($po->purchase_type !== 'N/A' ? $po->purchase_type : null);

            return [
                'id' => $po->id,
                'po_number' => $po->po_number,
                // vendor_name is stored directly on the PO for historical imports; also check relation
                'vendor_name' => ($po->vendor_name && $po->vendor_name !== '') ? $po->vendor_name : ($po->relationLoaded('vendor') ? ($po->vendor?->name ?? 'N/A') : 'N/A'),
                'vendor_address' => ($po->relationLoaded('vendor') ? ($po->vendor?->address ?? 'N/A') : 'N/A'),
                'vendor_tax_id' => ($po->relationLoaded('vendor') ? ($po->vendor?->formatted_tax_id ?? null) : null),
                'buyer_name' => ($po->relationLoaded('buyer') ? ($po->buyer?->name ?? 'N/A') : 'N/A'),
                'buyer_address' => ($po->relationLoaded('buyer') ? ($po->buyer?->address ?? 'N/A') : 'N/A'),
                'buyer_tax_id' => ($po->relationLoaded('buyer') ? ($po->buyer?->formatted_tax_id ?? null) : null),
                'department' => $po->department ?? 'N/A',
                'currency' => $po->currency ?? 'IDR',
                'purchase_category' => $po->purchase_category ?? 'N/A',
                'purchase_type' => $po->purchase_type ?? 'N/A',
                'payment_scheme' => $paymentScheme,
                // For historical POs, order_date is the actual PO date from the import file.
                // NEVER fall back to created_at (upload date) for historical — use null instead.
                'order_date' => $po->order_date?->format('Y-m-d') ?? ($po->is_historical ? null : $po->created_at->format('Y-m-d')),
                'expected_receiving_date' => $po->expected_receiving_date?->format('Y-m-d'),
                'delivery_point' => $po->delivery_point ?? $po->rfq?->delivery_point ?? null,
                'status' => $po->status,
                'is_historical' => $po->is_historical,
                'updated_at' => $po->updated_at->toIso8601String(),
                'created_by' => ($po->relationLoaded('creator') && $po->creator) ? $po->creator->name : ($po->created_by ?? 'N/A'),
                'approved_by' => ($po->relationLoaded('approver') && $po->approver) ? $po->approver->name : ($po->approved_by ?? 'N/A'),
                'total_amount' => $totalAmount ?? $po->total_amount,
                'items' => $mappedItems,
                'buyer_logo_url' => $buyerLogoUrl,
                'vendor_logo_url' => $vendorLogoUrl,
                // Use relationLoaded() guard so unloaded relations (e.g. historical POs) return [].
                'delivery_orders' => $po->relationLoaded('deliveryOrders')
                    ? $po->deliveryOrders->map(function ($do) {
                        return [
                            'id' => $do->id,
                            'do_number' => $do->do_number,
                            'tracking_number' => $do->tracking_number,
                            'delivery_address' => $do->delivery_address,
                            'status' => $do->status,
                            'handed_by_user_id' => $do->handed_by_user_id,
                            'handed_by_name' => $do->handed_by_name,
                            'handed_by_position' => $do->handed_by_position,
                            'handed_by_signed_at' => $do->handed_by_signed_at?->toIso8601String(),
                            'received_by_user_id' => $do->received_by_user_id,
                            'received_by_name' => $do->received_by_name,
                            'received_by_position' => $do->received_by_position,
                            'received_by_signed_at' => $do->received_by_signed_at?->toIso8601String(),
                            'witness_user_id' => $do->witness_user_id,
                            'witness_name' => $do->witness_name,
                            'witness_position' => $do->witness_position,
                            'witness_signed_at' => $do->witness_signed_at?->toIso8601String(),
                        ];
                    })->toArray() : [],
                'invoices' => $po->relationLoaded('invoices')
                    ? $po->invoices->map(function ($inv) {
                        return [
                            'id' => $inv->id,
                            'type' => $inv->type,
                            'amount' => $inv->amount,
                            'status' => $inv->status,
                            'date' => $inv->created_at->format('Y-m-d'),
                        ];
                    })->toArray() : [],
                'basts' => $po->relationLoaded('basts')
                    ? $po->basts->map(function ($bast) {
                        return [
                            'id' => $bast->id,
                            'bast_number' => $bast->bast_number,
                            'bast_date' => $bast->bast_date?->format('Y-m-d'),
                            'status' => $bast->status,
                            'handed_by_name' => $bast->handed_by_name,
                            'handed_by_signed_at' => $bast->handed_by_signed_at?->format('Y-m-d H:i:s'),
                            'received_by_name' => $bast->received_by_name,
                            'received_by_signed_at' => $bast->received_by_signed_at?->format('Y-m-d H:i:s'),
                            'witness_name' => $bast->witness_name,
                            'witness_signed_at' => $bast->witness_signed_at?->format('Y-m-d H:i:s'),
                        ];
                    })->toArray() : [],
                'efakturs' => $po->relationLoaded('efakturs')
                    ? $po->efakturs->map(function ($ef) {
                        return [
                            'id' => $ef->id,
                            'nofa' => $ef->nofa,
                            'transaction_id' => $ef->transaction_id,
                            'status' => $ef->status,
                            'tanggal_faktur' => $ef->tanggal_faktur,
                            'dpp' => $ef->dpp,
                            'ppn' => $ef->ppn,
                        ];
                    })->toArray() : [],
                'tracking_timeline' => $po->tracking_timeline ?? [],
            ];
        }, $items);
    }
}
