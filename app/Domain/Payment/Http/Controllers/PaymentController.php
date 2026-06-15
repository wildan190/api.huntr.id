<?php

namespace App\Domain\Payment\Http\Controllers;

use App\Domain\Order\Models\Invoice;
use App\Domain\Payment\Actions\CreatePaymentAction;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Jobs\ProcessPaymentSettlementJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Domain\Payment\Services\MidtransService;

class PaymentController extends \App\Http\Controllers\Controller
{
    public function __construct(
        protected MidtransService $midtrans
    ) {}

    /**
     * Get list of payments for a company.
     */
    public function index(Request $request): JsonResponse
    {
        $companyId = $request->query('company_id');
        if (!$companyId) {
            return response()->json(['message' => 'Company ID is required.'], 400);
        }

        $payments = Payment::with(['invoice.purchaseOrder'])
            ->whereHas('invoice.purchaseOrder', function($q) use ($companyId) {
                $q->where('buyer_company_id', $companyId)
                  ->orWhere('vendor_id', $companyId);
            })
            ->latest()
            ->paginate(10);

        return response()->json($payments);
    }

    /**
     * Initiate a payment for an invoice.
     */
    public function store(Request $request, CreatePaymentAction $action): JsonResponse
    {
        $request->validate([
            'invoice_id' => 'required|exists:invoices,id',
            'method' => 'required|string'
        ]);

        $invoice = Invoice::findOrFail($request->invoice_id);
        
        try {
            $payment = $action->execute($invoice, $request->method);
            return response()->json([
                'message' => 'Payment initiated successfully.',
                'payment' => $payment
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Midtrans Webhook Handler.
     */
    public function webhook(Request $request): JsonResponse
    {
        $payload = $request->all();
        Log::info('Midtrans Webhook Received', $payload);

        $orderId = $payload['order_id'] ?? null;
        if (!$orderId) return response()->json(['message' => 'Invalid payload'], 400);

        $payment = Payment::where('external_id', $orderId)->first();
        if (!$payment) return response()->json(['message' => 'Payment not found'], 404);

        $status = $payload['transaction_status'];
        
        // Dispatch job to process settlement in background via Horizon
        ProcessPaymentSettlementJob::dispatch($payment->id, $status, $payload);

        return response()->json(['message' => 'Webhook received and queued for processing']);
    }

    /**
     * Get payment status.
     */
    public function show($id, \App\Domain\Communication\Actions\BroadcastWebsocketNotificationAction $broadcastAction): JsonResponse
    {
        $payment = Payment::with(['invoice.purchaseOrder.buyer', 'invoice.purchaseOrder.vendor.users'])->where('id', $id)->first();
        
        if (!$payment) {
            return response()->json([
                'message' => 'Payment not found.',
                'payment' => null
            ], 404);
        }

        // If status is still pending, actively check Midtrans for updates
        if ($payment->status === 'pending') {
            try {
                $statusData = $this->midtrans->getStatus($payment->external_id);
                $newStatus = $statusData['transaction_status'] ?? 'pending';

                if ($newStatus !== 'pending' && $newStatus !== $payment->status) {
                    $payment->update([
                        'status' => $newStatus,
                        'transaction_id' => $statusData['transaction_id'] ?? $payment->transaction_id,
                        'raw_response' => array_merge($payment->raw_response ?? [], $statusData)
                    ]);

                    if ($newStatus === 'settlement' || $newStatus === 'capture') {
                        $payment->invoice->update([
                            'status' => 'paid',
                            // type stays as-is (proforma remains proforma after payment)
                        ]);
                        $po = $payment->invoice->purchaseOrder;
                        $po->update(['status' => 'paid']);

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
            } catch (\Exception $e) {
                Log::warning('Active payment status check failed', ['id' => $id, 'error' => $e->getMessage()]);
            }
        }

        return response()->json(['payment' => $payment]);
    }
}
