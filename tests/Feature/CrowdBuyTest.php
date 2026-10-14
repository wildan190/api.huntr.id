<?php

namespace Tests\Feature;

use App\Domain\Auth\Models\User;
use App\Domain\Company\Models\Company;
use App\Domain\CrowdBuy\Actions\EvaluateCampaignDeadlinesAction;
use App\Domain\CrowdBuy\Models\CrowdBuyCampaign;
use App\Domain\CrowdBuy\Models\CrowdBuyOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CrowdBuyTest extends TestCase
{
    use RefreshDatabase;

    protected Company $vendorCompany;
    protected User $vendorUser;
    protected Company $buyerCompany;
    protected User $buyerUser;
    protected User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Approved Vendor
        $this->vendorCompany = Company::create([
            'name' => 'PT Mitra Supplier Bulk',
            'type' => 'vendor',
            'status' => 'approved',
            'country' => 'ID',
            'email' => 'vendor@supplier.com',
            'phone' => '08123456789',
        ]);
        $this->vendorUser = User::create([
            'name' => 'Budi Supplier',
            'email' => 'budi@supplier.com',
            'password' => bcrypt('password'),
            'company_id' => $this->vendorCompany->id,
        ]);
        $this->vendorCompany->update(['owner_id' => $this->vendorUser->id]);

        // 2. Buyer Company
        $this->buyerCompany = Company::create([
            'name' => 'PT Pembeli Retail Sejahtera',
            'type' => 'buyer',
            'status' => 'approved',
            'country' => 'ID',
            'email' => 'buyer@retail.com',
            'phone' => '08987654321',
        ]);
        $this->buyerUser = User::create([
            'name' => 'Ani Buyer',
            'email' => 'ani@retail.com',
            'password' => bcrypt('password'),
            'company_id' => $this->buyerCompany->id,
        ]);
        $this->buyerCompany->update(['owner_id' => $this->buyerUser->id]);

        // 3. Admin User
        $this->adminUser = User::create([
            'name' => 'Super Admin',
            'email' => 'admin@huntr.id',
            'password' => bcrypt('password'),
        ]);
    }

    public function test_unverified_supplier_cannot_create_campaign(): void
    {
        $unverifiedCompany = Company::create([
            'name' => 'PT Supplier Baru',
            'type' => 'vendor',
            'status' => 'pending',
        ]);
        $unverifiedUser = User::create([
            'name' => 'Joko',
            'email' => 'joko@pending.com',
            'password' => bcrypt('password'),
            'company_id' => $unverifiedCompany->id,
        ]);

        $this->actingAs($unverifiedUser, 'api');

        $response = $this->postJson('/api/crowd-buy/supplier/campaigns', [
            'title' => 'Minyak Goreng Curah 1000L',
            'category' => 'Bahan Baku',
            'unit' => 'liter',
            'base_price' => 14000,
            'target_quantity' => 1000,
            'min_order_qty' => 50,
            'starts_at' => now()->toIso8601String(),
            'ends_at' => now()->addDays(7)->toIso8601String(),
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('crowd_buy_campaigns', 0);
    }

    public function test_approved_supplier_can_create_draft_and_submit_for_review(): void
    {
        $this->actingAs($this->vendorUser, 'api');

        // 1. Create draft
        $createResponse = $this->postJson('/api/crowd-buy/supplier/campaigns', [
            'title' => 'Gula Pasir Kristal Putih 50kg',
            'category' => 'Sembako',
            'unit' => 'karung',
            'base_price' => 650000,
            'target_quantity' => 200,
            'max_quantity' => 500,
            'min_order_qty' => 5,
            'starts_at' => now()->toIso8601String(),
            'ends_at' => now()->addDays(14)->toIso8601String(),
            'price_tiers' => [
                ['min_quantity' => 5, 'max_quantity' => 19, 'unit_price' => 650000],
                ['min_quantity' => 20, 'max_quantity' => null, 'unit_price' => 620000],
            ],
        ]);

        $createResponse->assertStatus(201);
        $campaignId = $createResponse->json('campaign.id');
        $this->assertDatabaseHas('crowd_buy_campaigns', [
            'id' => $campaignId,
            'status' => 'draft',
            'title' => 'Gula Pasir Kristal Putih 50kg',
        ]);
        $this->assertDatabaseCount('crowd_buy_price_tiers', 2);

        // 2. Submit for review
        $submitResponse = $this->postJson("/api/crowd-buy/supplier/campaigns/{$campaignId}/submit");
        $submitResponse->assertStatus(200);
        $this->assertDatabaseHas('crowd_buy_campaigns', [
            'id' => $campaignId,
            'status' => 'pending_review',
        ]);
    }

    public function test_admin_can_approve_campaign(): void
    {
        // Create pending_review campaign directly
        $campaign = CrowdBuyCampaign::create([
            'code' => 'CBC-TEST-001',
            'supplier_company_id' => $this->vendorCompany->id,
            'created_by' => $this->vendorUser->id,
            'title' => 'Tepung Terigu Segitiga 25kg',
            'category' => 'Bahan Baku',
            'unit' => 'karung',
            'base_price' => 210000,
            'target_quantity' => 100,
            'min_order_qty' => 5,
            'status' => 'pending_review',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(10),
        ]);

        $this->actingAs($this->adminUser, 'api');

        $response = $this->postJson("/api/admin/crowd-buy/campaigns/{$campaign->id}/review", [
            'action' => 'approve',
            'notes' => 'Dokumen dan kapasitas terverifikasi.',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('crowd_buy_campaigns', [
            'id' => $campaign->id,
            'status' => 'active',
        ]);
    }

    public function test_public_can_view_active_campaigns(): void
    {
        CrowdBuyCampaign::create([
            'code' => 'CBC-PUB-001',
            'supplier_company_id' => $this->vendorCompany->id,
            'created_by' => $this->vendorUser->id,
            'title' => 'Beras Pandan Wangi 50kg',
            'category' => 'Sembako',
            'unit' => 'karung',
            'base_price' => 700000,
            'target_quantity' => 50,
            'min_order_qty' => 2,
            'status' => 'active',
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addDays(5),
        ]);

        $response = $this->getJson('/api/crowd-buy/campaigns');
        $response->assertStatus(200);
        $response->assertJsonFragment(['title' => 'Beras Pandan Wangi 50kg']);
    }

    public function test_buyer_can_place_order_and_pay(): void
    {
        $campaign = CrowdBuyCampaign::create([
            'code' => 'CBC-ORDER-001',
            'supplier_company_id' => $this->vendorCompany->id,
            'created_by' => $this->vendorUser->id,
            'title' => 'Kopi Robusta Grade A 1kg',
            'category' => 'F&B',
            'unit' => 'pack',
            'base_price' => 85000,
            'target_quantity' => 100,
            'max_quantity' => 200,
            'min_order_qty' => 10,
            'current_quantity' => 0,
            'status' => 'active',
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addDays(7),
        ]);

        $this->actingAs($this->buyerUser, 'api');

        // 1. Place order below MOQ should fail
        $failMoq = $this->postJson("/api/crowd-buy/campaigns/{$campaign->id}/orders", [
            'quantity' => 5, // Below MOQ 10
            'shipping_address' => [
                'recipient_name' => 'Ani Store',
                'phone' => '08987654321',
                'address' => 'Jl. Sudirman No 10',
                'city' => 'Jakarta Selatan',
            ],
        ]);
        $failMoq->assertStatus(422);

        // 2. Place valid order
        $validOrder = $this->postJson("/api/crowd-buy/campaigns/{$campaign->id}/orders", [
            'quantity' => 20,
            'shipping_address' => [
                'recipient_name' => 'Ani Store',
                'phone' => '08987654321',
                'address' => 'Jl. Sudirman No 10',
                'city' => 'Jakarta Selatan',
            ],
            'notes' => 'Tolong packing rapi kardus tebal.',
        ]);

        $validOrder->assertStatus(201);
        $orderId = $validOrder->json('order.id');
        $this->assertEquals(1700000, $validOrder->json('order.total_amount'));

        // 3. Simulate payment
        $payResponse = $this->postJson("/api/crowd-buy/orders/{$orderId}/simulate-pay");
        $payResponse->assertStatus(200);

        // Check order status is now paid and campaign quantity incremented
        $this->assertDatabaseHas('crowd_buy_orders', [
            'id' => $orderId,
            'status' => 'paid',
        ]);
        $this->assertDatabaseHas('crowd_buy_campaigns', [
            'id' => $campaign->id,
            'current_quantity' => 20,
        ]);
    }

    public function test_deadline_evaluation_outcomes(): void
    {
        // Campaign A: target met -> successful
        $campaignSuccess = CrowdBuyCampaign::create([
            'code' => 'CBC-SUCC-001',
            'supplier_company_id' => $this->vendorCompany->id,
            'created_by' => $this->vendorUser->id,
            'title' => 'Campaign Sukses',
            'category' => 'Bahan Baku',
            'unit' => 'pcs',
            'base_price' => 50000,
            'target_quantity' => 50,
            'current_quantity' => 60, // Exceeded target
            'status' => 'active',
            'starts_at' => now()->subDays(5),
            'ends_at' => now()->subMinute(), // Expired
        ]);

        $orderSuccess = CrowdBuyOrder::create([
            'order_number' => 'CBO-SUCC-1',
            'campaign_id' => $campaignSuccess->id,
            'buyer_company_id' => $this->buyerCompany->id,
            'user_id' => $this->buyerUser->id,
            'quantity' => 60,
            'unit_price' => 50000,
            'subtotal' => 3000000,
            'total_amount' => 3000000,
            'shipping_address' => ['address' => 'Jakarta'],
            'status' => 'paid',
        ]);

        // Campaign B: target not met -> failed
        $campaignFail = CrowdBuyCampaign::create([
            'code' => 'CBC-FAIL-001',
            'supplier_company_id' => $this->vendorCompany->id,
            'created_by' => $this->vendorUser->id,
            'title' => 'Campaign Gagal',
            'category' => 'Bahan Baku',
            'unit' => 'pcs',
            'base_price' => 50000,
            'target_quantity' => 100,
            'current_quantity' => 20, // Failed target
            'status' => 'active',
            'starts_at' => now()->subDays(5),
            'ends_at' => now()->subMinute(), // Expired
        ]);

        $orderFail = CrowdBuyOrder::create([
            'order_number' => 'CBO-FAIL-1',
            'campaign_id' => $campaignFail->id,
            'buyer_company_id' => $this->buyerCompany->id,
            'user_id' => $this->buyerUser->id,
            'quantity' => 20,
            'unit_price' => 50000,
            'subtotal' => 1000000,
            'total_amount' => 1000000,
            'shipping_address' => ['address' => 'Bandung'],
            'status' => 'paid',
        ]);

        // Run evaluation action
        $action = app(EvaluateCampaignDeadlinesAction::class);
        $results = $action->execute();

        $this->assertEquals(1, $results['successful']);
        $this->assertEquals(1, $results['failed']);

        $this->assertEquals('successful', $campaignSuccess->fresh()->status);
        $this->assertEquals('processing', $orderSuccess->fresh()->status);

        $this->assertEquals('failed', $campaignFail->fresh()->status);
        $this->assertEquals('refund_pending', $orderFail->fresh()->status);
    }

    public function test_supplier_order_fulfillment(): void
    {
        $campaign = CrowdBuyCampaign::create([
            'code' => 'CBC-FULFILL-001',
            'supplier_company_id' => $this->vendorCompany->id,
            'created_by' => $this->vendorUser->id,
            'title' => 'Campaign Fulfillment',
            'category' => 'General',
            'unit' => 'pcs',
            'base_price' => 10000,
            'target_quantity' => 10,
            'current_quantity' => 10,
            'status' => 'successful',
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->subDay(),
        ]);

        $order = CrowdBuyOrder::create([
            'order_number' => 'CBO-FULFILL-001',
            'campaign_id' => $campaign->id,
            'buyer_company_id' => $this->buyerCompany->id,
            'user_id' => $this->buyerUser->id,
            'quantity' => 10,
            'unit_price' => 10000,
            'subtotal' => 100000,
            'total_amount' => 100000,
            'shipping_address' => ['address' => 'Surabaya'],
            'status' => 'processing',
        ]);

        $this->actingAs($this->vendorUser, 'api');

        $response = $this->postJson("/api/crowd-buy/supplier/orders/{$order->id}/fulfill", [
            'status' => 'shipped',
            'carrier' => 'JNE Cargo',
            'tracking_number' => 'JNEC1234567890',
            'notes' => 'Barang sudah diberangkatkan dari gudang utama.',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('crowd_buy_orders', [
            'id' => $order->id,
            'status' => 'shipped',
            'carrier' => 'JNE Cargo',
            'tracking_number' => 'JNEC1234567890',
        ]);
    }

    public function test_artisan_command_evaluates_deadlines(): void
    {
        $this->artisan('crowd-buy:evaluate-deadlines')
            ->expectsOutputToContain('Starting Crowd Buy deadline evaluation...')
            ->assertExitCode(0);
    }
}
