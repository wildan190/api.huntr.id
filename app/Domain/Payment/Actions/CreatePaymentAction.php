<?php

namespace App\Domain\Payment\Actions;

use App\Domain\Order\Models\Invoice;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Services\MidtransService;
use Illuminate\Support\Str;

class CreatePaymentAction
{
    public function __construct(
        private readonly MidtransService $midtrans
    ) {}

    public function execute(Invoice $invoice, string $method): Payment
    {
        $externalId = 'PAY-' . strtoupper(Str::random(10));
        
        $payload = [
            'transaction_details' => [
                'order_id' => $externalId,
                'gross_amount' => (int) $invoice->amount,
            ],
            'item_details' => [
                [
                    'id' => $invoice->id,
                    'price' => (int) $invoice->amount,
                    'quantity' => 1,
                    'name' => 'Payment for PO ' . $invoice->purchaseOrder->po_number,
                ]
            ],
            'customer_details' => [
                'first_name' => $invoice->purchaseOrder->buyer?->name ?? 'Buyer',
                'email' => $invoice->purchaseOrder->buyer?->email ?? 'buyer@example.com',
            ],
            'callbacks' => [
                'finish' => config('app.frontend_url', 'http://localhost:5173') . '/orders?payment=success',
            ],
        ];

        // Override notification URL if set in env (Global for Core API)
        if (config('services.midtrans.notification_url')) {
            $payload['metadata'] = [
                'notification_url' => config('services.midtrans.notification_url')
            ];
        }

        // Handle Payment Methods
        switch ($method) {
            case 'qris':
                $payload['payment_type'] = 'qris';
                break;
            case 'bca_va':
                $payload['payment_type'] = 'bank_transfer';
                $payload['bank_transfer'] = ['bank' => 'bca'];
                break;
            case 'mandiri_va':
                $payload['payment_type'] = 'echannel';
                $payload['echannel'] = [
                    'bill_info1' => 'Payment for Invoice',
                    'bill_info2' => $invoice->purchaseOrder->po_number
                ];
                break;
            case 'bni_va':
                $payload['payment_type'] = 'bank_transfer';
                $payload['bank_transfer'] = ['bank' => 'bni'];
                break;
            case 'bri_va':
                $payload['payment_type'] = 'bank_transfer';
                $payload['bank_transfer'] = ['bank' => 'bri'];
                break;
            case 'dana':
                $payload['payment_type'] = 'dana';
                $payload['dana'] = [
                    'callback_url' => config('app.frontend_url', 'http://localhost:5173') . '/orders?payment=success'
                ];
                break;
            default:
                throw new \Exception("Payment method {$method} is not supported.");
        }

        $response = $this->midtrans->charge($payload);

        return Payment::create([
            'invoice_id' => $invoice->id,
            'external_id' => $externalId,
            'transaction_id' => $response['transaction_id'] ?? null,
            'amount' => $invoice->amount,
            'payment_type' => $payload['payment_type'],
            'payment_method' => $method,
            'status' => 'pending',
            'payment_info' => $this->extractPaymentInfo($response, $method),
            'raw_response' => $response,
        ]);
    }

    private function extractPaymentInfo(array $response, string $method): array
    {
        $info = [];
        if ($method === 'qris') {
            $info['qr_url'] = $response['actions'][0]['url'] ?? null;
        } elseif (str_contains($method, '_va')) {
            if ($method === 'mandiri_va') {
                $info['bill_key'] = $response['bill_key'] ?? null;
                $info['biller_code'] = $response['biller_code'] ?? null;
            } else {
                $info['va_number'] = $response['va_numbers'][0]['va_number'] ?? null;
            }
        } elseif ($method === 'dana') {
            $info['checkout_url'] = $response['actions'][0]['url'] ?? null;
        }
        return $info;
    }
}
