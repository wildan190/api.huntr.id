<?php

namespace Tests\Domain\Order;

use App\Domain\Access\Models\Role;
use App\Domain\Auth\Models\User;
use App\Domain\Company\Models\Company;
use App\Domain\Order\Models\DeliveryOrder;
use App\Domain\Order\Models\PurchaseOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderDomainTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\AccessControlSeeder::class);
    }

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

        $response = $this->actingAs($user, 'api')
            ->getJson("/api/orders?company_id={$company->id}");

        $response->assertStatus(200)
            ->assertJsonStructure(['data', 'total']);
    }

    /** @test */
    public function manager_can_sign_delivery_order_as_received_by()
    {
        $user = User::factory()->create(['name' => 'Buyer Manager']);
        $user->assignRole('manager');

        $company = Company::factory()->create(['owner_id' => $user->id, 'type' => 'buyer']);
        $po = PurchaseOrder::create([
            'po_number' => 'PO-2026-002',
            'buyer_company_id' => $company->id,
            'status' => 'confirmed',
            'currency' => 'IDR',
        ]);

        $deliveryOrder = DeliveryOrder::create([
            'purchase_order_id' => $po->id,
            'do_number' => 'DO-2026-002',
            'tracking_number' => 'TRK-001',
            'status' => 'shipped',
        ]);

        $response = $this->actingAs($user, 'api')
            ->postJson("/api/do/{$deliveryOrder->id}/sign-received-by", [
                'received_by_user_id' => $user->id,
                'received_by_name' => $user->name,
                'received_by_position' => 'Manager',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Signature recorded successfully.')
            ->assertJsonPath('do.id', $deliveryOrder->id)
            ->assertJsonPath('signature_status.received_by_signed', true);

        $this->assertNotNull($response->json('do.received_by_signed_at'));
        $this->assertDatabaseHas('delivery_orders', [
            'id' => $deliveryOrder->id,
            'received_by_user_id' => $user->id,
        ]);
    }
}
