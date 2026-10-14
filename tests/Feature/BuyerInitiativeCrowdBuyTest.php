<?php

namespace Tests\Feature;

use App\Domain\Auth\Models\User;
use App\Domain\Company\Models\Company;
use App\Domain\CrowdBuy\Models\CrowdBuyBid;
use App\Domain\CrowdBuy\Models\CrowdBuyCampaign;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BuyerInitiativeCrowdBuyTest extends TestCase
{
    protected Company $supplierCompany;
    protected Company $buyerCompanyA;
    protected Company $buyerCompanyB;
    protected User $supplierUser;
    protected User $buyerUserA;
    protected User $buyerUserB;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Supplier Company
        $this->supplierCompany = Company::create([
            'name' => 'PT Mitra Supplier Jaya',
            'type' => 'vendor',
            'status' => 'approved',
            'country' => 'ID',
        ]);
        $this->supplierUser = User::factory()->create([
            'company_id' => $this->supplierCompany->id,
        ]);

        // 2. Buyer Company A (Initiator)
        $this->buyerCompanyA = Company::create([
            'name' => 'PT Buyer Inisiator',
            'type' => 'buyer',
            'status' => 'approved',
            'country' => 'ID',
        ]);
        $this->buyerUserA = User::factory()->create([
            'company_id' => $this->buyerCompanyA->id,
        ]);

        // 3. Buyer Company B (Invited)
        $this->buyerCompanyB = Company::create([
            'name' => 'PT Rekan Buyer',
            'type' => 'buyer',
            'status' => 'approved',
            'country' => 'ID',
        ]);
        $this->buyerUserB = User::factory()->create([
            'company_id' => $this->buyerCompanyB->id,
        ]);
    }

    public function test_buyer_can_create_catalog_group_buy_with_invite_code(): void
    {
        $payload = [
            'campaign_type' => 'buyer_catalog',
            'supplier_company_id' => $this->supplierCompany->id,
            'title' => 'Group Buy Kardus 20x20x10 Dus Box',
            'description' => 'Patungan beli kardus langsung pabrik',
            'category' => 'Packaging & Logistics',
            'unit' => 'pcs',
            'base_price' => 25000,
            'target_quantity' => 1000,
            'min_order_qty' => 50,
            'ends_at' => now()->addDays(14)->toDateTimeString(),
            'initial_quantity' => 100,
        ];

        $response = $this->actingAs($this->buyerUserA, 'api')
            ->postJson('/api/crowd-buy/initiatives', $payload);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'message',
            'campaign' => ['id', 'code', 'invite_code', 'status', 'creator_type'],
            'invite_code',
        ]);

        $campaign = CrowdBuyCampaign::where('title', 'Group Buy Kardus 20x20x10 Dus Box')->first();
        $this->assertNotNull($campaign);
        $this->assertEquals('buyer', $campaign->creator_type);
        $this->assertEquals('buyer_catalog', $campaign->campaign_type);
        $this->assertEquals('active', $campaign->status);
        $this->assertNotNull($campaign->invite_code);
        $this->assertEquals(100, $campaign->current_quantity);
    }

    public function test_buyer_can_create_collective_rfq_and_supplier_can_bid(): void
    {
        // 1. Buyer A creates Collective RFQ
        $payload = [
            'campaign_type' => 'collective_rfq',
            'title' => 'Collective RFQ: Botol Plastik HDPE 500ml',
            'description' => 'Kebutuhan botol HDPE tebal 1mm tutup segel warna putih',
            'category' => 'Raw Materials',
            'unit' => 'pcs',
            'target_price' => 3500,
            'target_quantity' => 5000,
            'min_order_qty' => 500,
            'ends_at' => now()->addDays(10)->toDateTimeString(),
            'initial_quantity' => 1000,
        ];

        $response = $this->actingAs($this->buyerUserA, 'api')
            ->postJson('/api/crowd-buy/initiatives', $payload);

        $response->assertStatus(201);
        $campaignId = $response->json('campaign.id');
        $inviteCode = $response->json('invite_code');

        $this->assertNotNull($inviteCode);

        // 2. Buyer B can resolve the invite code
        $inviteRes = $this->getJson("/api/crowd-buy/invite/{$inviteCode}");
        $inviteRes->assertStatus(200);
        $inviteRes->assertJsonPath('campaign.title', 'Collective RFQ: Botol Plastik HDPE 500ml');

        // 3. Supplier submits a bid
        $bidPayload = [
            'offered_unit_price' => 3200,
            'min_order_qty' => 500,
            'lead_time_days' => 5,
            'notes' => 'Kami bisa supply botol HDPE grade A dengan kapasitas cetak 20.000/hari',
        ];

        $bidRes = $this->actingAs($this->supplierUser, 'api')
            ->postJson("/api/crowd-buy/supplier/collective-rfqs/{$campaignId}/bids", $bidPayload);

        $bidRes->assertStatus(201);
        $bidId = $bidRes->json('bid.id');

        $this->assertDatabaseHas('crowd_buy_bids', [
            'id' => $bidId,
            'campaign_id' => $campaignId,
            'supplier_company_id' => $this->supplierCompany->id,
            'offered_unit_price' => 3200,
            'status' => 'pending',
        ]);

        // 4. Buyer A accepts the Supplier's bid
        $acceptRes = $this->actingAs($this->buyerUserA, 'api')
            ->postJson("/api/crowd-buy/initiatives/{$campaignId}/accept-bid/{$bidId}");

        $acceptRes->assertStatus(200);

        // Verify campaign is now active with winning supplier
        $campaign = CrowdBuyCampaign::find($campaignId);
        $this->assertEquals('active', $campaign->status);
        $this->assertEquals($this->supplierCompany->id, $campaign->supplier_company_id);
        $this->assertEquals(3200, (float) $campaign->base_price);

        // Verify bid is accepted
        $bid = CrowdBuyBid::find($bidId);
        $this->assertEquals('accepted', $bid->status);
    }

    public function test_buyer_can_fetch_registered_suppliers(): void
    {
        $res = $this->actingAs($this->buyerUserA, 'api')
            ->getJson('/api/crowd-buy/suppliers');

        $res->assertStatus(200);
        $res->assertJsonFragment([
            'id' => $this->supplierCompany->id,
            'name' => $this->supplierCompany->name,
        ]);
    }

    public function test_validation_requires_supplier_company_id_for_buyer_catalog(): void
    {
        $payload = [
            'campaign_type' => 'buyer_catalog',
            // Missing supplier_company_id
            'title' => 'Catalog Group Buy Without Supplier',
            'category' => 'Packaging',
            'unit' => 'pcs',
            'base_price' => 25000,
            'target_quantity' => 100,
            'ends_at' => now()->addDays(7)->toDateTimeString(),
        ];

        $res = $this->actingAs($this->buyerUserA, 'api')
            ->postJson('/api/crowd-buy/initiatives', $payload);

        $res->assertStatus(422);
        $res->assertJsonValidationErrors(['supplier_company_id']);
    }
}

