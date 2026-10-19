<?php

namespace App\Domain\CrowdBuy\Services;

use App\Domain\Auth\Models\User;
use App\Domain\Company\Models\Company;
use App\Domain\CrowdBuy\Models\CrowdBuyCampaign;
use App\Domain\CrowdBuy\Models\CrowdBuyPriceTier;
use App\Domain\CrowdBuy\Repositories\CrowdBuyRepositoryInterface;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AutoDemandAggregationService
{
    public function __construct(
        protected ?CrowdBuyRepositoryInterface $repository = null
    ) {}

    /**
     * Analyze historical PO items across all companies in HUNTR.
     * Identifies items frequently bought on a monthly average with their lowest historical unit price.
     *
     * @param array $options
     * @return Collection
     */
    public function analyzeDemands(array $options = []): Collection
    {
        $minFrequency = $options['min_frequency'] ?? 1;
        $limit = $options['limit'] ?? 20;

        // Query historical PO items grouped by inventory item name
        $query = DB::table('historical_po_items')
            ->join('purchase_orders', 'historical_po_items.purchase_order_id', '=', 'purchase_orders.id')
            ->selectRaw("
                TRIM(historical_po_items.inventory_name) as item_name,
                MAX(historical_po_items.inventory_code) as inventory_code,
                MAX(historical_po_items.category) as category,
                MAX(historical_po_items.uom) as uom,
                COUNT(DISTINCT purchase_orders.buyer_company_id) as buyer_companies_count,
                COUNT(historical_po_items.id) as total_orders_count,
                SUM(historical_po_items.qty) as total_quantity,
                MIN(NULLIF(historical_po_items.unit_price, 0)) as lowest_unit_price,
                AVG(NULLIF(historical_po_items.unit_price, 0)) as avg_unit_price,
                MIN(purchase_orders.order_date) as earliest_order_date,
                MAX(purchase_orders.order_date) as latest_order_date
            ")
            ->whereNotNull('historical_po_items.inventory_name')
            ->where('historical_po_items.inventory_name', '!=', '')
            ->where('historical_po_items.unit_price', '>', 0)
            ->groupByRaw("TRIM(historical_po_items.inventory_name)")
            ->havingRaw("COUNT(historical_po_items.id) >= ?", [$minFrequency]);

        $rawResults = $query->get();

        $demands = $rawResults->map(function ($row) {
            $totalQty = (float) ($row->total_quantity ?? 0);
            $totalOrders = (int) ($row->total_orders_count ?? 1);
            $lowestPrice = (float) ($row->lowest_unit_price ?? 0);
            $avgPrice = (float) ($row->avg_unit_price ?? 0);

            // Determine month span between earliest and latest order date
            $monthsSpan = 1;
            if (!empty($row->earliest_order_date) && !empty($row->latest_order_date)) {
                try {
                    $start = Carbon::parse($row->earliest_order_date);
                    $end = Carbon::parse($row->latest_order_date);
                    $days = abs($start->diffInDays($end));
                    $monthsSpan = max(1, (int) ceil($days / 30));
                } catch (\Throwable) {
                    $monthsSpan = 1;
                }
            }

            $monthlyAvgQty = round($totalQty / $monthsSpan, 2);
            $monthlyOrderFrequency = round($totalOrders / $monthsSpan, 2);

            // Find best vendor / supplier offering the lowest historical price
            $bestVendorRecord = DB::table('historical_po_items')
                ->join('purchase_orders', 'historical_po_items.purchase_order_id', '=', 'purchase_orders.id')
                ->whereRaw("TRIM(historical_po_items.inventory_name) = ?", [$row->item_name])
                ->where('historical_po_items.unit_price', '=', $row->lowest_unit_price)
                ->select([
                    'purchase_orders.vendor_id',
                    'purchase_orders.vendor_name',
                ])
                ->first();

            $vendorId = $bestVendorRecord?->vendor_id;
            $vendorName = $bestVendorRecord?->vendor_name;

            // Verify if vendor_id matches a registered company in HUNTR
            $supplierCompanyId = null;
            if (!empty($vendorId)) {
                $isRegistered = DB::table('companies')->where('id', $vendorId)->exists();
                if ($isRegistered) {
                    $supplierCompanyId = $vendorId;
                }
            }

            // Sensible target & MOQ defaults
            $targetQuantity = max(10, (int) ceil($monthlyAvgQty));
            $minOrderQty = max(1, (int) round($monthlyAvgQty * 0.05));

            return [
                'item_name' => $row->item_name,
                'inventory_code' => $row->inventory_code,
                'category' => $row->category ?: 'General',
                'uom' => $row->uom ?: 'pcs',
                'buyer_companies_count' => (int) $row->buyer_companies_count,
                'total_orders_count' => $totalOrders,
                'total_quantity' => $totalQty,
                'months_span' => $monthsSpan,
                'monthly_average_qty' => $monthlyAvgQty,
                'monthly_order_frequency' => $monthlyOrderFrequency,
                'lowest_unit_price' => $lowestPrice,
                'avg_unit_price' => round($avgPrice, 2),
                'target_quantity' => $targetQuantity,
                'min_order_qty' => $minOrderQty,
                'best_vendor_id' => $supplierCompanyId,
                'best_vendor_name' => $vendorName,
            ];
        });

        // Sort by highest monthly frequency, monthly average volume, and lowest price
        return $demands->sort(function ($a, $b) {
            if ($b['monthly_order_frequency'] != $a['monthly_order_frequency']) {
                return $b['monthly_order_frequency'] <=> $a['monthly_order_frequency'];
            }
            if ($b['buyer_companies_count'] != $a['buyer_companies_count']) {
                return $b['buyer_companies_count'] <=> $a['buyer_companies_count'];
            }
            return $b['monthly_average_qty'] <=> $a['monthly_average_qty'];
        })->values()->take($limit);
    }

    /**
     * Generate or synchronize automated CrowdBuy campaigns from historical PO data.
     *
     * @param array $options
     * @param User|null $actor
     * @return array
     */
    public function generateCampaigns(array $options = [], ?User $actor = null): array
    {
        $demands = $this->analyzeDemands($options);
        $created = [];
        $updated = [];

        // Fallback user ID for system actions
        $creatorId = $actor?->id
            ?? User::whereRaw("id::text in (select model_id from model_roles join roles on roles.id = model_roles.role_id where roles.slug = 'admin')")->value('id')
            ?? User::first()?->id
            ?? Str::uuid()->toString();

        // Standard delivery day: 27th of every month
        $deliveryDay = 27;

        // Cutoff end date: 25th of current month if before 25th, otherwise 25th of next month
        $now = now();
        if ($now->day <= 25) {
            $endsAt = $now->copy()->setDay(25)->endOfDay();
        } else {
            $endsAt = $now->copy()->addMonth()->setDay(25)->endOfDay();
        }

        $deliveryTerms = "Barang akan dikirim setiap tanggal {$deliveryDay} setiap bulannya ke lokasi sesuai delivery point.";

        foreach ($demands as $demand) {
            if ($demand['lowest_unit_price'] <= 0) {
                continue;
            }

            // Check if active recurring campaign for this item already exists
            $existing = CrowdBuyCampaign::where('title', $demand['item_name'])
                ->where('is_monthly_recurring', true)
                ->whereIn('status', ['active', 'bidding', 'scheduled'])
                ->first();

            $metrics = [
                'monthly_average_qty' => $demand['monthly_average_qty'],
                'monthly_order_frequency' => $demand['monthly_order_frequency'],
                'lowest_historical_price' => $demand['lowest_unit_price'],
                'avg_historical_price' => $demand['avg_unit_price'],
                'buyer_companies_count' => $demand['buyer_companies_count'],
                'total_historical_orders' => $demand['total_orders_count'],
                'best_vendor_name' => $demand['best_vendor_name'],
            ];

            if ($existing) {
                // Update historical metrics and benchmark price if lowest decreased
                $existing->update([
                    'historical_metrics' => $metrics,
                    'base_price' => min((float) $existing->base_price, $demand['lowest_unit_price']),
                    'target_price' => min((float) ($existing->target_price ?: $existing->base_price), $demand['lowest_unit_price']),
                    'delivery_terms' => $deliveryTerms,
                    'monthly_delivery_day' => $deliveryDay,
                ]);
                $updated[] = $existing->fresh();
                continue;
            }

            $dateStr = now()->format('Ymd');
            $random = strtoupper(Str::random(4));
            $code = "CBC-AUTO-{$dateStr}-{$random}";

            $campaign = CrowdBuyCampaign::create([
                'code' => $code,
                'creator_type' => 'system',
                'campaign_type' => 'auto_demand_aggregation',
                'is_monthly_recurring' => true,
                'monthly_delivery_day' => $deliveryDay,
                'historical_metrics' => $metrics,
                'title' => $demand['item_name'],
                'description' => "Pengumpulan permintaan otomatis oleh algoritma HUNTR membaca data riwayat PO seluruh buyer. Barang dengan frekuensi pembelian rutin bulanan dikumpulkan otomatis untuk mengunci harga terendah historis Rp " . number_format($demand['lowest_unit_price'], 0, ',', '.') . " / {$demand['uom']}.",
                'category' => $demand['category'],
                'unit' => $demand['uom'],
                'base_price' => $demand['lowest_unit_price'],
                'target_price' => $demand['lowest_unit_price'],
                'target_quantity' => $demand['target_quantity'],
                'min_order_qty' => $demand['min_order_qty'],
                'current_quantity' => 0,
                'status' => 'active',
                'starts_at' => now(),
                'ends_at' => $endsAt,
                'lead_time_days' => 5,
                'delivery_terms' => $deliveryTerms,
                'supplier_company_id' => $demand['best_vendor_id'],
                'created_by' => $creatorId,
            ]);

            // Add tiered pricing for volume discounts beyond the monthly target
            if ($demand['target_quantity'] > 0) {
                // Tier 1: Target volume benchmark
                CrowdBuyPriceTier::create([
                    'campaign_id' => $campaign->id,
                    'min_quantity' => $demand['target_quantity'],
                    'max_quantity' => (int) round($demand['target_quantity'] * 1.5),
                    'unit_price' => $demand['lowest_unit_price'],
                ]);

                // Tier 2: Extra 5% discount for 1.5x+ crowd volume
                CrowdBuyPriceTier::create([
                    'campaign_id' => $campaign->id,
                    'min_quantity' => (int) round($demand['target_quantity'] * 1.5) + 1,
                    'max_quantity' => null,
                    'unit_price' => round($demand['lowest_unit_price'] * 0.95, 2),
                ]);
            }

            $created[] = $campaign->fresh();
        }

        return [
            'total_analyzed' => $demands->count(),
            'created_count' => count($created),
            'updated_count' => count($updated),
            'created_campaigns' => $created,
            'updated_campaigns' => $updated,
        ];
    }
}
