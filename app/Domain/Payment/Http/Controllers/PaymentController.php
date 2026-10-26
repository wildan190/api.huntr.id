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
use App\Domain\Subscription\Actions\RecordRealizedGmvAction;
use App\Domain\Company\Models\Company;
use App\Domain\Company\Services\CompanyAccessService;

use App\Domain\Payment\Http\Requests\StorePaymentRequest;

class PaymentController extends \App\Http\Controllers\Controller
{
    public function __construct(
        protected MidtransService $midtrans
    ) {}

    /**
     * Get list of payments for a company.
     */
    public function index(Request $request, CompanyAccessService $access): JsonResponse
    {
        $companyId = $request->query('company_id');
        if (!$companyId) {
            return response()->json(['message' => 'Company ID is required.'], 400);
        }

        $company = Company::findOrFail($companyId);
        $access->assertMember($request->user(), $company);

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
    public function store(StorePaymentRequest $request, CreatePaymentAction $action, CompanyAccessService $access): JsonResponse
    {
        $validated = $request->validated();

        $invoice = Invoice::findOrFail($validated['invoice_id']);
        $buyer = $invoice->purchaseOrder->buyer;
        abort_unless($buyer, 404, 'Buyer perusahaan tidak ditemukan.');
        $access->assertCan($request->user(), $buyer, 'finance.access', 'Role Anda belum memiliki izin untuk membuat pembayaran.');
        
        try {
            $payment = $action->execute($invoice, $validated['method']);
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
    public function webhook(Request $request, \App\Domain\Subscription\Services\SubscriptionPaymentService $subscriptionPayments): JsonResponse
    {
        $payload = $request->all();

        $orderId = $payload['order_id'] ?? null;
        if (!$orderId) return response()->json(['message' => 'Invalid payload'], 400);

        abort_unless($this->midtrans->verifyWebhookSignature($payload), 403, 'Signature Midtrans tidak valid.');

        if (str_starts_with((string) $orderId, 'SUB-')) {
            $subscriptionPayments->acceptWebhook($payload);
            return response()->json(['message' => 'Subscription payment received']);
        }

        $payment = Payment::where('external_id', $orderId)->first();
        if (!$payment) return response()->json(['message' => 'Payment not found'], 404);

        $status = (string) ($payload['transaction_status'] ?? '');
        abort_unless(in_array($status, ['pending', 'capture', 'settlement', 'deny', 'cancel', 'expire', 'failure'], true), 422, 'Status transaksi Midtrans tidak valid.');
        abort_unless(
            abs((float) ($payload['gross_amount'] ?? 0) - (float) $payment->amount) < 0.01,
            422,
            'Nominal webhook tidak sesuai dengan pembayaran.',
        );
        
        // Dispatch job to process settlement in background via Horizon
        ProcessPaymentSettlementJob::dispatch($payment->id, $status, $payload);

        return response()->json(['message' => 'Webhook received and queued for processing']);
    }

    /**
     * Get payment status.
     */
    public function show(
        Request $request,
        $id,
        \App\Domain\Communication\Actions\BroadcastWebsocketNotificationAction $broadcastAction,
        RecordRealizedGmvAction $recordRealizedGmvAction,
        CompanyAccessService $access,
    ): JsonResponse
    {
        $payment = Payment::with(['invoice.purchaseOrder.buyer', 'invoice.purchaseOrder.vendor.users'])->where('id', $id)->first();
        
        if (!$payment) {
            return response()->json([
                'message' => 'Payment not found.',
                'payment' => null
            ], 404);
        }
        $po = $payment->invoice->purchaseOrder;
        $buyer = $po?->buyer;
        $vendor = $po?->vendor;
        abort_unless(
            ($buyer && $access->isMember($request->user(), $buyer))
            || ($vendor && $access->isMember($request->user(), $vendor)),
            403,
            'Anda tidak memiliki akses ke pembayaran ini.',
        );

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
                        $recordRealizedGmvAction->execute($payment->invoice->fresh());
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
