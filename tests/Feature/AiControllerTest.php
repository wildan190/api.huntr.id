<?php

namespace Tests\Feature;

use App\Domain\AI\Services\GenkitService;
use App\Domain\Auth\Models\User;
use App\Domain\Catalogue\Models\Catalogue;
use App\Domain\Company\Models\Company;
use App\Domain\Rfq\Models\Rfq;
use App\Domain\Rfq\Models\RfqItem;
use App\Domain\Proposal\Models\Proposal;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiControllerTest extends TestCase
{
    private User $buyerUser;
    private Company $buyerCompany;

    protected function setUp(): void
    {
        parent::setUp();

        // Create user and company for authenticated tests
        $this->buyerCompany = Company::create([
            'name' => 'Buyer Corp',
            'type' => 'buyer',
            'status' => 'approved',
        ]);

        $this->buyerUser = User::create([
            'name' => 'Buyer User',
            'email' => 'buyer@example.com',
            'password' => bcrypt('password'),
            'company_id' => $this->buyerCompany->id,
            'role' => 'buyer',
            'status' => 'active',
        ]);
    }

    public function test_ai_search_endpoint(): void
    {
        $vendorCompany = Company::create([
            'name' => 'Vendor Corp',
            'type' => 'vendor',
            'status' => 'approved',
        ]);

        $product = Catalogue::create([
            'company_id' => $vendorCompany->id,
            'item_code' => 'TEST-001',
            'name' => 'Laptop Asus ROG',
            'category' => 'Laptop',
            'brand' => 'Asus',
            'specifications' => 'Intel i7, 16GB RAM, RTX 3060',
            'uom' => 'Unit',
        ]);

        // Mock GenkitService
        $this->mock(GenkitService::class, function ($mock) {
            $mock->shouldReceive('extractSearchIntent')
                ->once()
                ->with('laptop gaming spec tinggi')
                ->andReturn([
                    'keywords' => ['laptop', 'rog'],
                    'category' => 'Laptop',
                    'brand' => 'Asus',
                    'specs' => ['gaming', 'tinggi'],
                    'quantity_hint' => null,
                    'ai_summary' => 'Mencari laptop Asus ROG untuk gaming'
                ]);
        });

        $response = $this->postJson('/api/ai/search', [
            'query' => 'laptop gaming spec tinggi',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'is_ai_search' => true,
                'ai_summary' => 'Mencari laptop Asus ROG untuk gaming',
            ]);

        $this->assertNotEmpty($response->json('data'));
        $this->assertEquals($product->id, $response->json('data.0.id'));
    }

    public function test_ai_compare_endpoint(): void
    {
        $vendorCompany = Company::create([
            'name' => 'Vendor Corp',
            'type' => 'vendor',
            'status' => 'approved',
        ]);

        $product1 = Catalogue::create([
            'company_id' => $vendorCompany->id,
            'item_code' => 'TEST-001',
            'name' => 'Laptop Asus ROG',
            'category' => 'Laptop',
            'brand' => 'Asus',
            'specifications' => 'i7, 16GB RAM',
            'uom' => 'Unit',
        ]);

        $product2 = Catalogue::create([
            'company_id' => $vendorCompany->id,
            'item_code' => 'TEST-002',
            'name' => 'Laptop HP Omen',
            'category' => 'Laptop',
            'brand' => 'HP',
            'specifications' => 'i7, 32GB RAM',
            'uom' => 'Unit',
        ]);

        // Mock GenkitService comparison
        $this->mock(GenkitService::class, function ($mock) use ($product1, $product2) {
            $mock->shouldReceive('compareProducts')
                ->once()
                ->andReturn([
                    'comparison_matrix' => [
                        [
                            'catalogue_id' => $product1->id,
                            'product_name' => 'Laptop Asus ROG',
                            'score' => 85,
                            'pros' => ['Desain gaming bagus'],
                            'cons' => ['RAM lebih kecil'],
                        ],
                        [
                            'catalogue_id' => $product2->id,
                            'product_name' => 'Laptop HP Omen',
                            'score' => 90,
                            'pros' => ['RAM 32GB besar'],
                            'cons' => ['Lebih berat'],
                        ]
                    ],
                    'recommended_id' => $product2->id,
                    'recommendation' => 'HP Omen direkomendasikan karena RAM 32GB.',
                    'summary' => 'Perbandingan laptop gaming Asus vs HP.'
                ]);
        });

        $response = $this->postJson('/api/ai/compare', [
            'catalogue_ids' => [$product1->id, $product2->id],
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'ai_analysis' => [
                    'recommended_id' => $product2->id,
                ]
            ]);
    }

    public function test_ai_rank_proposals_requires_auth(): void
    {
        $response = $this->postJson('/api/ai/rank-proposals', [
            'rfq_id' => 'some-uuid',
        ]);

        $response->assertStatus(401);
    }

    public function test_ai_generate_pr_requires_auth(): void
    {
        $response = $this->postJson('/api/ai/generate-pr', [
            'query' => 'Saya butuh printer laserjet 2 unit',
        ]);

        $response->assertStatus(401);
    }

    public function test_ai_generate_pr_endpoint_with_auth(): void
    {
        $vendorCompany = Company::create([
            'name' => 'Vendor Corp',
            'type' => 'vendor',
            'status' => 'approved',
        ]);

        $product = Catalogue::create([
            'company_id' => $vendorCompany->id,
            'item_code' => 'TEST-003',
            'name' => 'Printer HP Laserjet',
            'category' => 'Office',
            'brand' => 'HP',
            'specifications' => 'Laserjet black and white',
            'uom' => 'Unit',
        ]);

        $this->mock(GenkitService::class, function ($mock) use ($product) {
            $mock->shouldReceive('generatePrDraft')
                ->once()
                ->andReturn([
                    'title' => 'Pengadaan Printer Kantor',
                    'description' => 'Membeli printer laserjet untuk kebutuhan operasional kantor.',
                    'suggested_items' => [
                        [
                            'catalogue_id' => $product->id,
                            'qty' => 2,
                            'estimated_price' => 2500000.0,
                            'reason' => 'Printer handal dan ekonomis'
                        ]
                    ],
                    'duration_days' => 7,
                    'priority' => 'Normal',
                    'notes' => 'Tolong di-approve'
                ]);
        });

        $response = $this->actingAs($this->buyerUser, 'api')
            ->postJson('/api/ai/generate-pr', [
                'query' => 'Saya butuh printer laserjet 2 unit untuk kantor',
                'catalogue_ids' => [$product->id],
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'draft' => [
                    'title' => 'Pengadaan Printer Kantor',
                    'source' => 'ai'
                ]
            ]);

        $this->assertNotEmpty($response->json('draft.suggested_items'));
        $this->assertEquals($product->id, $response->json('draft.suggested_items.0.catalogue_id'));
    }

    public function test_ai_rank_proposals_endpoint_with_auth(): void
    {
        $rfq = Rfq::create([
            'company_id' => $this->buyerCompany->id,
            'title' => 'Request for Office Laptop',
            'description' => 'Need laptops ASAP',
            'deadline' => now()->addDays(7),
            'duration_days' => 7,
            'status' => 'open',
        ]);

        $vendorCompany = Company::create([
            'name' => 'Vendor Asus Corp',
            'type' => 'vendor',
            'status' => 'approved',
        ]);

        $proposal = Proposal::create([
            'rfq_id' => $rfq->id,
            'company_id' => $vendorCompany->id,
            'price_offer' => 15000000.0,
            'delivery_days' => 5,
            'warranty_months' => 12,
            'payment_term' => '30 days',
            'status' => 'submitted',
            'document_path' => 'proposal_documents/test.pdf',
        ]);

        $this->mock(GenkitService::class, function ($mock) use ($proposal) {
            $mock->shouldReceive('rankProposals')
                ->once()
                ->andReturn([
                    'rankings' => [
                        [
                            'proposal_id' => $proposal->id,
                            'rank' => 1,
                            'is_winner' => true,
                            'total_score' => 95,
                            'strengths' => ['Harga bagus'],
                            'weaknesses' => [],
                            'recommendation' => 'Paling murah',
                        ]
                    ],
                    'overall_analysis' => 'Proposal paling optimal',
                    'recommended_winner_id' => $proposal->id,
                ]);
        });

        $response = $this->actingAs($this->buyerUser, 'api')
            ->postJson('/api/ai/rank-proposals', [
                'rfq_id' => $rfq->id,
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'recommended_winner_id' => $proposal->id,
                ]
            ]);

        $rankings = $response->json('data.rankings');
        $this->assertNotEmpty($rankings);
        $this->assertEquals($proposal->id, $rankings[0]['proposal']['id']);
        $this->assertEquals('proposal_documents/test.pdf', $rankings[0]['proposal']['document_path']);
        $this->assertNotNull($rankings[0]['proposal']['document_url']);
    }
}
