<?php

namespace Tests\Domain\Order;

use App\Domain\Auth\Models\User;
use App\Domain\Company\Models\Company;
use App\Domain\Order\Models\PurchaseOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderDomainTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function authenticated_user_can_get_purchase_orders()
    {
        $user = User::factory()->create();
        $company = Company::factory()->create(['owner_id' => $user->id, 'type' => 'buyer']);
        
        PurchaseOrder::create([
            'po_number' => 'PO-2026-001',
            'buyer_company_id' => $company->id,
            'status' => 'draft',
            'currency' => 'IDR',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/orders?company_id={$company->id}");

        $response->assertStatus(200)
            ->assertJsonStructure(['data', 'total']);
    }
}
