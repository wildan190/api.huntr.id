<?php

namespace Tests\Feature;

use App\Domain\Auth\Models\User;
use App\Domain\Company\Models\Company;
use App\Domain\CrowdBuy\Models\CrowdBuyCampaign;
use App\Domain\CrowdBuy\Models\CrowdBuyOrder;
use App\Domain\CrowdBuy\Services\AutoDemandAggregationService;
use App\Domain\Order\Models\HistoricalPoItem;
use App\Domain\Order\Models\PurchaseOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AutoDemandAggregationTest extends TestCase
{
    protected Company $buyerCompanyA;
    protected Company $buyerCompanyB;
    protected Company $supplierCompany;
    protected User $buyerUserA;
    protected User $buyerUserB;
    protected User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Buyer Companies
        $this->buyerCompanyA = Company::create([
            'name' => 'PT Manufaktur Plastik Nusantara',
            'type' => 'buyer',
            'status' => 'approved',
            'country' => 'ID',
            'address' => 'Kawasan Industri MM2100 Blok C-4, Cikarang Barat',
        ]);
        $this->buyerUserA = User::factory()->create([
            'company_id' => $this->buyerCompanyA->id,
        ]);

        $this->buyerCompanyB = Company::create([
            'name' => 'PT Kemasan Berdikari',
            'type' => 'buyer',
            'status' => 'approved',
            'country' => 'ID',
            'address' => 'Jl. Rungkut Industri Raya No. 12, Surabaya',
        ]);
        $this->buyerUserB = User::factory()->create([
            'company_id' => $this->buyerCompanyB->id,
        ]);

        // 2. Supplier
        $this->supplierCompany = Company::create([
            'name' => 'PT Mitra Karton Utama',
            'type' => 'vendor',
            'status' => 'approved',
            'country' => 'ID',
        ]);

        // 3. Admin User
        $this->adminUser = User::create([
            'name' => 'Super Admin',
            'email' => 'admin@huntr.id',
            'password' => bcrypt('password'),
        ]);
    }

    protected function seedHistoricalPoData(): void
    {
        // PO 1 from Buyer A: Bought Kardus Box at Rp 12.000 (Qty 500)
        $po1 = PurchaseOrder::create([
            'buyer_company_id' => $this->buyerCompanyA->id,
            'vendor_id' => $this->supplierCompany->id,
            'vendor_name' => $this->supplierCompany->name,
            'po_number' => 'PO-HIST-001',
            'currency' => 'IDR',
            'total_amount' => 6000000,
            'order_date' => now()->subMonths(2),
            'delivery_point' => 'Gudang Cikarang',
            'is_historical' => true,
        ]);

        HistoricalPoItem::create([
            'purchase_order_id' => $po1->id,
            'inventory_name' => 'Kardus Master Box 60x40x40',
            'category' => 'Packaging',
            'uom' => 'pcs',
            'qty' => 500,
            'unit_price' => 12000,
            'amount' => 6000000,
            'currency' => 'IDR',
            'order_date' => now()->subMonths(2),
        ]);

        // PO 2 from Buyer A: Bought Kardus Box again at Rp 10.500 (Qty 600) - Lower Price
        $po2 = PurchaseOrder::create([
            'buyer_company_id' => $this->buyerCompanyA->id,
            'vendor_id' => $this->supplierCompany->id,
            'vendor_name' => $this->supplierCompany->name,
            'po_number' => 'PO-HIST-002',
            'currency' => 'IDR',
            'total_amount' => 6300000,
            'order_date' => now()->subMonth(),
            'delivery_point' => 'Gudang Cikarang',
            'is_historical' => true,
        ]);

        HistoricalPoItem::create([
            'purchase_order_id' => $po2->id,
            'inventory_name' => 'Kardus Master Box 60x40x40',
            'category' => 'Packaging',
            'uom' => 'pcs',
            'qty' => 600,
            'unit_price' => 10500,
            'amount' => 6300000,
            'currency' => 'IDR',
            'order_date' => now()->subMonth(),
        ]);

        // PO 3 from Buyer B: Also bought same Kardus Box at Rp 11.000 (Qty 800)
        $po3 = PurchaseOrder::create([
            'buyer_company_id' => $this->buyerCompanyB->id,
            'vendor_id' => $this->supplierCompany->id,
            'vendor_name' => $this->supplierCompany->name,
            'po_number' => 'PO-HIST-003',
            'currency' => 'IDR',
            'total_amount' => 8800000,
            'order_date' => now()->subWeeks(2),
            'delivery_point' => 'Gudang Rungkut',
            'is_historical' => true,
        ]);

        HistoricalPoItem::create([
            'purchase_order_id' => $po3->id,
            'inventory_name' => 'Kardus Master Box 60x40x40',
            'category' => 'Packaging',
            'uom' => 'pcs',
            'qty' => 800,
            'unit_price' => 11000,
            'amount' => 8800000,
            'currency' => 'IDR',
            'order_date' => now()->subWeeks(2),
        ]);
    }

    public function test_algorithm_analyzes_historical_po_demands_and_detects_lowest_price(): void
    {
        $this->seedHistoricalPoData();

        /** @var AutoDemandAggregationService $service */
        $service = app(AutoDemandAggregationService::class);
        $demands = $service->analyzeDemands();

        $this->assertNotEmpty($demands);
        $topItem = $demands->first();

        $this->assertEquals('Kardus Master Box 60x40x40', $topItem['item_name']);
        // Lowest unit price among 12000, 10500, 11000 is 10500
        $this->assertEquals(10500, $topItem['lowest_unit_price']);
        // Buyer companies count should be 2
        $this->assertEquals(2, $topItem['buyer_companies_count']);
        // Total orders count should be 3
        $this->assertEquals(3, $topItem['total_orders_count']);
        // Total quantity should be 1900
        $this->assertEquals(1900, $topItem['total_quantity']);
    }

    public function test_algorithm_auto_generates_recurring_crowdbuy_campaign_with_monthly_delivery_schedule(): void
    {
        $this->seedHistoricalPoData();

        /** @var AutoDemandAggregationService $service */
        $service = app(AutoDemandAggregationService::class);
        $result = $service->generateCampaigns([], $this->adminUser);

        $this->assertGreaterThanOrEqual(1, $result['created_count']);

        $campaign = CrowdBuyCampaign::where('title', 'Kardus Master Box 60x40x40')->first();
        $this->assertNotNull($campaign);

        // Verify key properties requested by Deny:
        // 1. Automated demand aggregation type
        $this->assertEquals('auto_demand_aggregation', $campaign->campaign_type);
        $this->assertEquals('system', $campaign->creator_type);
        $this->assertTrue($campaign->is_monthly_recurring);
        $this->assertTrue($campaign->is_auto_demand);

        // 2. Lowest price is picked as base and target price
        $this->assertEquals(10500, (float) $campaign->base_price);
        $this->assertEquals(10500, (float) $campaign->target_price);

        // 3. Delivery on the 27th of every month
        $this->assertEquals(27, $campaign->monthly_delivery_day);
        $this->assertStringContainsString('27', $campaign->delivery_terms);
        $this->assertStringContainsString('delivery point', $campaign->delivery_terms);
        $this->assertStringContainsString('27', $campaign->monthly_delivery_schedule);
    }

    public function test_buyer_can_join_auto_demand_campaign_with_monthly_quantity_commitment(): void
    {
        $this->seedHistoricalPoData();

        /** @var AutoDemandAggregationService $service */
        $service = app(AutoDemandAggregationService::class);
        $service->generateCampaigns([], $this->adminUser);

        $campaign = CrowdBuyCampaign::where('title', 'Kardus Master Box 60x40x40')->first();

        // Buyer A commits to 150 pcs per month
        $payload = [
            'quantity' => 150,
            'is_monthly_commitment' => true,
            'monthly_commitment_qty' => 150,
            'delivery_point' => 'Kawasan Industri MM2100 Blok C-4, Cikarang',
            'shipping_address' => [
                'recipient_name' => 'Budi Santoso (Logistik)',
                'phone' => '081298765432',
                'address' => 'Kawasan Industri MM2100 Blok C-4, Cikarang',
                'city' => 'Bekasi',
            ],
            'notes' => 'Pengiriman rutin bulanan ke Dock 2',
        ];

        $response = $this->actingAs($this->buyerUserA, 'api')
            ->postJson("/api/crowd-buy/campaigns/{$campaign->id}/orders", $payload);

        $response->assertStatus(201);
        $orderId = $response->json('order.id');

        $order = CrowdBuyOrder::find($orderId);
        $this->assertNotNull($order);
        $this->assertEquals(150, $order->quantity);
        $this->assertTrue($order->is_monthly_commitment);
        $this->assertEquals(150, $order->monthly_commitment_qty);
        $this->assertEquals('Kawasan Industri MM2100 Blok C-4, Cikarang', $order->delivery_point);
    }

    public function test_artisan_command_aggregates_demands(): void
    {
        $this->seedHistoricalPoData();

        $this->artisan('crowd-buy:aggregate-demands')
            ->expectsOutputToContain('Starting HUNTR historical PO demand aggregation...')
            ->expectsOutputToContain('Aggregation completed.')
            ->assertExitCode(0);

        $this->assertDatabaseHas('crowd_buy_campaigns', [
            'title' => 'Kardus Master Box 60x40x40',
            'campaign_type' => 'auto_demand_aggregation',
            'is_monthly_recurring' => true,
        ]);
    }

    public function test_admin_can_trigger_auto_demand_aggregation_via_api(): void
    {
        $this->seedHistoricalPoData();

        $response = $this->actingAs($this->adminUser, 'api')
            ->postJson('/api/admin/crowd-buy/auto-aggregate', ['min_freq' => 1]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.total_analyzed', 1);
        $response->assertJsonPath('data.created_count', 1);
    }
}
