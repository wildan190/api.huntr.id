<?php

namespace Tests\Feature;

use App\Domain\Auth\Models\User;
use App\Domain\Catalogue\Models\Catalogue;
use App\Domain\Company\Models\Company;
use App\Domain\Order\Models\HistoricalPoItem;
use App\Domain\Order\Models\PurchaseOrder;
use App\Domain\Proposal\Actions\AwardMultiWinnersAction;
use App\Domain\Proposal\Models\Proposal;
use App\Domain\Proposal\Models\ProposalItem;
use App\Domain\Rfq\Models\Rfq;
use App\Domain\Rfq\Models\RfqItem;
use App\Domain\Rfq\Services\ItemPriceHistoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeparateOrderAndLastPriceTest extends TestCase
{
    use RefreshDatabase;

    protected Company $buyerCompany;
    protected User $buyerUser;
    protected Company $vendor1;
    protected User $vendor1User;
    protected Company $vendor2;
    protected User $vendor2User;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\AccessControlSeeder::class);

        // Setup buyer company & user
        $this->buyerCompany = Company::create([
            'name' => 'PT Mandiri Makmur Sejahtera',
            'type' => 'buyer',
            'status' => 'approved',
            'country' => 'ID',
        ]);
        $this->buyerUser = User::create([
            'name' => 'Buyer Procurement Manager',
            'email' => 'buyer@mandiri.com',
            'password' => bcrypt('password'),
            'company_id' => $this->buyerCompany->id,
        ]);
        $this->buyerCompany->update(['owner_id' => $this->buyerUser->id]);
        $this->buyerUser->assignRole('manager');

        // Setup Vendor 1
        $this->vendor1 = Company::create([
            'name' => 'PT Vendor Alpha Perkasa',
            'type' => 'vendor',
            'status' => 'approved',
            'country' => 'ID',
        ]);
        $this->vendor1User = User::create([
            'name' => 'Vendor Alpha User',
            'email' => 'alpha@vendor.com',
            'password' => bcrypt('password'),
            'company_id' => $this->vendor1->id,
        ]);
        $this->vendor1->update(['owner_id' => $this->vendor1User->id]);

        // Setup Vendor 2
        $this->vendor2 = Company::create([
            'name' => 'PT Vendor Beta Jaya',
            'type' => 'vendor',
            'status' => 'approved',
            'country' => 'ID',
        ]);
        $this->vendor2User = User::create([
            'name' => 'Vendor Beta User',
            'email' => 'beta@vendor.com',
            'password' => bcrypt('password'),
            'company_id' => $this->vendor2->id,
        ]);
        $this->vendor2->update(['owner_id' => $this->vendor2User->id]);
    }

    /**
     * Test Last Purchase Price resolution:
     * Priority 1: Historical purchase
     * Priority 2: Target price from PR
     * Priority 3: 0
     */
    public function test_last_price_resolution_priorities(): void
    {
        $service = app(ItemPriceHistoryService::class);

        // 1. Create a historical PO item for the buyer
        $pastPo = PurchaseOrder::create([
            'buyer_company_id' => $this->buyerCompany->id,
            'po_number' => 'PO-HISTORICAL-001',
            'status' => 'completed',
            'is_historical' => true,
        ]);
        HistoricalPoItem::create([
            'purchase_order_id' => $pastPo->id,
            'inventory_name' => 'Seal Oil SANY 400ml',
            'inventory_code' => 'SO-400',
            'unit_price' => 250000,
            'qty' => 10,
            'currency' => 'IDR',
            'order_date' => now()->subMonths(2),
        ]);

        // RFQ Item 1: Matches historical item by SKU/Name -> Should resolve to historical 250,000
        $item1 = new RfqItem([
            'item_name' => 'Seal Oil SANY 400ml',
            'sku' => 'SO-400',
            'qty' => 5,
            'estimated_price' => 300000, // PR target price is 300,000, but historical is 250,000
        ]);
        $res1 = $service->resolveLastPrice($item1, (string) $this->buyerCompany->id);
        $this->assertEquals(250000, $res1['price']);
        $this->assertEquals('historical', $res1['source']);

        // RFQ Item 2: Never purchased before, but has estimated_price -> Should resolve to target_price
        $item2 = new RfqItem([
            'item_name' => 'Barang Baru Belum Pernah Beli',
            'sku' => 'NEW-999',
            'qty' => 2,
            'estimated_price' => 150000,
        ]);
        $res2 = $service->resolveLastPrice($item2, (string) $this->buyerCompany->id);
        $this->assertEquals(150000, $res2['price']);
        $this->assertEquals('target_price', $res2['source']);

        // RFQ Item 3: Never purchased before, estimated_price is 0/null -> Should resolve to 0
        $item3 = new RfqItem([
            'item_name' => 'Barang Tanpa Riwayat Dan Tanpa Pagu',
            'sku' => 'ZERO-000',
            'qty' => 1,
            'estimated_price' => 0,
        ]);
        $res3 = $service->resolveLastPrice($item3, (string) $this->buyerCompany->id);
        $this->assertEquals(0, $res3['price']);
        $this->assertEquals('none', $res3['source']);
    }

    /**
     * Test Separate Order Awarding:
     * Buyer splits 2 items:
     * - Item 1 goes to Vendor 1
     * - Item 2 goes to Vendor 2
     * Verifies that two separate Purchase Orders are generated, each containing ONLY its awarded item and correct total.
     */
    public function test_separate_order_award_generates_distinct_purchase_orders(): void
    {
        // 1. Create RFQ with 2 items
        $rfq = Rfq::create([
            'company_id' => $this->buyerCompany->id,
            'user_id' => $this->buyerUser->id,
            'title' => 'Pengadaan Sparepart Alat Berat',
            'status' => 'active',
            'procurement_type' => 'goods',
            'award_mode' => 'single',
        ]);

        $item1 = RfqItem::create([
            'rfq_id' => $rfq->id,
            'item_name' => 'Seal Oil Hydraulic',
            'qty' => 4,
            'estimated_price' => 200000,
        ]);

        $item2 = RfqItem::create([
            'rfq_id' => $rfq->id,
            'item_name' => 'O-Ring High Pressure',
            'qty' => 10,
            'estimated_price' => 50000,
        ]);

        // 2. Vendor 1 submits proposal for both items
        $proposal1 = Proposal::create([
            'rfq_id' => $rfq->id,
            'company_id' => $this->vendor1->id,
            'price_offer' => 1300000,
            'delivery_days' => 7,
            'winner_status' => 'none',
            'status' => 'submitted',
            'payment_term' => 'TOP 30 Days',
        ]);
        ProposalItem::create([
            'proposal_id' => $proposal1->id,
            'rfq_item_id' => $item1->id,
            'price_offer' => 180000, // 4 * 180k = 720k
        ]);
        ProposalItem::create([
            'proposal_id' => $proposal1->id,
            'rfq_item_id' => $item2->id,
            'price_offer' => 58000,  // 10 * 58k = 580k
        ]);

        // 3. Vendor 2 submits proposal for both items
        $proposal2 = Proposal::create([
            'rfq_id' => $rfq->id,
            'company_id' => $this->vendor2->id,
            'price_offer' => 1250000,
            'delivery_days' => 14,
            'winner_status' => 'none',
            'status' => 'submitted',
            'payment_term' => 'Cash before delivery',
        ]);
        ProposalItem::create([
            'proposal_id' => $proposal2->id,
            'rfq_item_id' => $item1->id,
            'price_offer' => 210000, // 4 * 210k = 840k
        ]);
        ProposalItem::create([
            'proposal_id' => $proposal2->id,
            'rfq_item_id' => $item2->id,
            'price_offer' => 41000,  // 10 * 41k = 410k (Cheaper on item 2!)
        ]);

        // 4. Buyer decides to do SEPARATE ORDER:
        // Item 1 awarded to Vendor 1 (price 180,000 * 4 = 720,000)
        // Item 2 awarded to Vendor 2 (price 41,000 * 10 = 410,000)
        $this->actingAs($this->buyerUser, 'api');

        $payload = [
            'award_mode' => 'separate_order',
            'generate_po' => true,
            'user_id' => $this->buyerUser->id,
            'awards' => [
                [
                    'proposal_id' => $proposal1->id,
                    'item_ids' => [$item1->id],
                ],
                [
                    'proposal_id' => $proposal2->id,
                    'item_ids' => [$item2->id],
                ],
            ],
        ];

        $response = $this->postJson("/api/rfqs/{$rfq->id}/award-winners", $payload);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Pemenang tender dan Purchase Order berhasil ditetapkan.',
            ]);

        // 5. Verify Database Records
        $rfq->refresh();
        $this->assertEquals('awarded', $rfq->status);
        $this->assertEquals('separate_order', $rfq->award_mode);

        // Verify Proposal 1 awarded items & status
        $proposal1->refresh();
        $this->assertEquals('approved', $proposal1->winner_status);
        $this->assertEquals(720000, $proposal1->awarded_amount);
        $this->assertTrue(ProposalItem::where('proposal_id', $proposal1->id)->where('rfq_item_id', $item1->id)->first()->is_awarded);
        $this->assertFalse(ProposalItem::where('proposal_id', $proposal1->id)->where('rfq_item_id', $item2->id)->first()->is_awarded);

        // Verify Proposal 2 awarded items & status
        $proposal2->refresh();
        $this->assertEquals('approved', $proposal2->winner_status);
        $this->assertEquals(410000, $proposal2->awarded_amount);
        $this->assertFalse(ProposalItem::where('proposal_id', $proposal2->id)->where('rfq_item_id', $item1->id)->first()->is_awarded);
        $this->assertTrue(ProposalItem::where('proposal_id', $proposal2->id)->where('rfq_item_id', $item2->id)->first()->is_awarded);

        // Verify 2 distinct Purchase Orders are created
        $pos = PurchaseOrder::where('rfq_id', $rfq->id)->get();
        $this->assertCount(2, $pos);

        $po1 = $pos->where('proposal_id', $proposal1->id)->first();
        $this->assertNotNull($po1);
        $this->assertEquals($this->vendor1->id, $po1->vendor_id);
        $this->assertEquals(720000, $po1->total_amount);

        $po2 = $pos->where('proposal_id', $proposal2->id)->first();
        $this->assertNotNull($po2);
        $this->assertEquals($this->vendor2->id, $po2->vendor_id);
        $this->assertEquals(410000, $po2->total_amount);
    }
}
