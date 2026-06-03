<?php

namespace App\Domain\Payment\Tests;

use App\Domain\Auth\Models\User;
use App\Domain\Company\Models\Company;
use App\Domain\Order\Models\Invoice;
use App\Domain\Order\Models\PurchaseOrder;
use App\Domain\Payment\Models\Payment;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    protected Company $company;
    protected User $user;
    protected PurchaseOrder $po;
    protected Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Mock Midtrans configuration for testing
        config(['services.midtrans.server_key' => 'test-server-key']);
        config(['services.midtrans.is_production' => false]);
        
        // Setup initial data
        $this->company = Company::create([
            'name' => 'Test Buyer',
            'type' => 'buyer',
            'status' => 'approved'
        ]);

        $this->user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
            'company_id' => $this->company->id
        ]);

        $this->po = PurchaseOrder::create([
            'buyer_company_id' => $this->company->id,
            'po_number' => 'PO-TEST-001',
            'status' => 'confirmed'
        ]);

        $this->invoice = Invoice::create([
            'purchase_order_id' => $this->po->id,
            'amount' => 1000000,
            'status' => 'unpaid'
        ]);
    }

    /** @test */
    public function it_can_initiate_qris_payment()
    {
        Http::fake([
            'api.sandbox.midtrans.com/v2/charge' => Http::response([
                'transaction_id' => 'midtrans-tx-123',
                'order_id' => 'PAY-TEST-QRIS',
                'gross_amount' => '1000000.00',
                'payment_type' => 'qris',
                'transaction_status' => 'pending',
                'actions' => [
                    ['name' => 'generate-qr-code', 'url' => 'https://api.sandbox.midtrans.com/v2/qris/midtrans-tx-123/qr-code']
                ]
            ], 200)
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/payments', [
                'invoice_id' => $this->invoice->id,
                'method' => 'qris'
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('payment.payment_type', 'qris')
            ->assertJsonPath('payment.payment_info.qr_url', 'https://api.sandbox.midtrans.com/v2/qris/midtrans-tx-123/qr-code');

        $this->assertDatabaseHas('payments', [
            'invoice_id' => $this->invoice->id,
            'payment_method' => 'qris',
            'status' => 'pending'
        ]);
    }

    /** @test */
    public function it_can_handle_midtrans_webhook_settlement()
    {
        $payment = Payment::create([
            'invoice_id' => $this->invoice->id,
            'external_id' => 'PAY-EXTERNAL-123',
            'amount' => 1000000,
            'status' => 'pending'
        ]);

        $payload = [
            'order_id' => 'PAY-EXTERNAL-123',
            'transaction_status' => 'settlement',
            'transaction_id' => 'midtrans-tx-123',
            'gross_amount' => '1000000.00',
            'payment_type' => 'qris',
            'signature_key' => 'dummy-signature'
        ];

        $response = $this->postJson('/api/payments/webhook', $payload);

        $response->assertStatus(200);

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'settlement'
        ]);

        $this->assertDatabaseHas('invoices', [
            'id' => $this->invoice->id,
            'status' => 'paid'
        ]);

        $this->assertDatabaseHas('purchase_orders', [
            'id' => $this->po->id,
            'status' => 'paid'
        ]);
    }

    /** @test */
    public function it_fails_if_invoice_does_not_exist()
    {
        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/payments', [
                'invoice_id' => 'non-existent-uuid',
                'method' => 'qris'
            ]);

        $response->assertStatus(422);
    }
}
