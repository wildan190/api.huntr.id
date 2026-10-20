<?php

namespace App\Domain\Payment\Actions;

use App\Domain\Order\Models\Invoice;
use App\Domain\Order\Actions\BuildInvoicePaymentBreakdownAction;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Services\MidtransService;
use Illuminate\Support\Str;

class CreatePaymentAction
{
    public function __construct(
        private readonly MidtransService $midtrans,
        private readonly BuildInvoicePaymentBreakdownAction $buildInvoicePaymentBreakdownAction,
    ) {}

    public function execute(Invoice $invoice, string $method): Payment
    {
        $supportedMethods = ['bca_va', 'cimb_va', 'permata_va', 'mandiri_va', 'bni_va', 'bri_va'];
        if (! in_array($method, $supportedMethods, true)) {
            throw new \Exception("Payment method {$method} is not supported.");
        }

        $externalId = 'PAY-' . strtoupper(Str::random(10));
        
        $breakdown = $this->buildInvoicePaymentBreakdownAction->execute($invoice);
        $grossAmount = max(1, (int) round($breakdown['total_amount']));
        $itemDetails = [[
            'id' => $invoice->id . '-po',
            'price' => $grossAmount,
            'quantity' => 1,
            'name' => 'Pembayaran PO ' . $invoice->purchaseOrder->po_number,
        ]];

        $payload = [
            'transaction_details' => [
                'order_id' => $externalId,
                'gross_amount' => $grossAmount,
            ],
            'item_details' => $itemDetails,
            'customer_details' => [
                'first_name' => $invoice->purchaseOrder->buyer?->name ?? 'Buyer',
                'email' => $invoice->purchaseOrder->buyer?->email ?? 'buyer@example.com',
            ],
        ];

        // Handle Payment Methods
        switch ($method) {
            case 'bca_va':
                $payload['payment_type'] = 'bank_transfer';
                $payload['bank_transfer'] = ['bank' => 'bca'];
                break;
            case 'cimb_va':
                $payload['payment_type'] = 'bank_transfer';
                $payload['bank_transfer'] = ['bank' => 'cimb'];
                break;
            case 'permata_va':
                $payload['payment_type'] = 'bank_transfer';
                $payload['bank_transfer'] = ['bank' => 'permata'];
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
            default:
                throw new \Exception("Payment method {$method} is not supported.");
        }

        $response = $this->midtrans->charge($payload);

        return Payment::create([
            'invoice_id'     => $invoice->id,
            'external_id'    => $externalId,
            'transaction_id' => $response['transaction_id'] ?? null,
            'amount'         => $grossAmount,
            'payment_type'   => $payload['payment_type'],
            'payment_method' => $method,
            'status'         => 'pending',
            'payment_info'   => $this->extractPaymentInfo($response, $method),
            'raw_response'   => $response,
        ]);
    }

    private function extractPaymentInfo(array $response, string $method): array
    {
        $info = [];
        if (str_contains($method, '_va') || $method === 'mandiri_va') {
            if ($method === 'mandiri_va') {
                $info['bill_key'] = $response['bill_key'] ?? null;
                $info['biller_code'] = $response['biller_code'] ?? null;
            } else {
                $info['va_number'] = $response['va_numbers'][0]['va_number'] ?? null;
            }
        }
        return $info;
    }
}
