<?php

namespace App\Domain\Payment\Jobs;

use App\Domain\Payment\Models\Payment;
use App\Domain\Communication\Actions\BroadcastWebsocketNotificationAction;
use App\Domain\Subscription\Actions\RecordRealizedGmvAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessPaymentSettlementJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        protected string $paymentId,
        protected string $status,
        protected array $payload
    ) {}

    /**
     * Execute the job.
     */
    public function handle(
        BroadcastWebsocketNotificationAction $broadcastAction,
        RecordRealizedGmvAction $recordRealizedGmvAction,
    ): void
    {
        $payment = Payment::with(['invoice.purchaseOrder.buyer', 'invoice.purchaseOrder.vendor.users'])->find($this->paymentId);
        
        if (!$payment) {
            Log::error('PaymentSettlementJob: Payment not found', ['id' => $this->paymentId]);
            return;
        }

        $po = $payment->invoice->purchaseOrder;

        Log::info('Processing Payment Settlement in Background', [
            'payment_id' => $payment->id,
            'status' => $this->status
        ]);

        $payment->update([
            'status' => $this->status,
            'transaction_id' => $this->payload['transaction_id'] ?? $payment->transaction_id,
            'raw_response' => array_merge($payment->raw_response ?? [], $this->payload)
        ]);

        // If payment is settled, update invoice and PO status
        if ($this->status === 'settlement' || $this->status === 'capture') {
            $payment->invoice->update([
                'status' => 'paid',
                // type stays as-is (proforma remains proforma after payment)
            ]);
            $recordRealizedGmvAction->execute($payment->invoice->fresh());
            $po->update(['status' => 'paid']);
            
            \Illuminate\Support\Facades\Log::info('Payment Settlement Successful', [
                'invoice_id' => $payment->invoice_id,
                'po_id' => $po->id
            ]);

            // 1. Notify the Buyer (The one who paid)
            $broadcastAction->execute(
                "Payment Successful",
                "Your payment for PO {$po->po_number} has been confirmed. Thank you!",
                'test-channel',
                true,
                $po->created_by,
                "/orders?search={$po->po_number}"
            );

            // 2. Notify the Vendor (The one receiving money)
            $vendorUserIds = collect($po->vendor->users->pluck('id'))->push($po->vendor->owner_id)->unique()->filter();
            foreach ($vendorUserIds as $vendorUserId) {
                $broadcastAction->execute(
                    "Payment Received",
                    "Buyer has completed payment for PO {$po->po_number}. Please prepare for delivery.",
                    'test-channel',
                    true,
                    $vendorUserId,
                    "/orders?search={$po->po_number}"
                );
            }
        }
    }
}
