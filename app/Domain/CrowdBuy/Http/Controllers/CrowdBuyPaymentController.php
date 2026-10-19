<?php

namespace App\Domain\CrowdBuy\Http\Controllers;

use App\Domain\CrowdBuy\Actions\ProcessCrowdBuyPaymentAction;
use App\Domain\CrowdBuy\Models\CrowdBuyOrder;
use App\Domain\CrowdBuy\Models\CrowdBuyPayment;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CrowdBuyPaymentController extends Controller
{
    /**
     * Initiate payment for an order.
     */
    public function pay(Request $request, string $orderId, ProcessCrowdBuyPaymentAction $action): JsonResponse
    {
        $user = $request->user();
        $order = CrowdBuyOrder::with('campaign')->findOrFail($orderId);

        if ($order->buyer_company_id !== $user->company_id && !$user->hasRole('admin')) {
            return response()->json(['message' => 'Anda tidak berwenang membayar order ini.'], 403);
        }

        try {
            $paymentMethod = $request->input('payment_method', 'bank_transfer');
            $payment = $action->initiatePayment($order, $paymentMethod);

            return response()->json([
                'message' => 'Pembayaran berhasil diinisiasi.',
                'payment' => $payment,
            ]);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Simulate payment settlement in sandbox / development.
     */
    public function simulateSuccess(Request $request, string $orderId, ProcessCrowdBuyPaymentAction $action): JsonResponse
    {
        $user = $request->user();
        $order = CrowdBuyOrder::with('campaign')->findOrFail($orderId);

        if ($order->buyer_company_id !== $user->company_id && !$user->hasRole('admin')) {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        $transactionId = 'SIM-TRX-' . time();
        $paidOrder = $action->markAsPaid($order, $transactionId, [
            'simulated' => true,
            'paid_by_user_id' => $user->id,
            'time' => now()->toIso8601String(),
        ]);

        return response()->json([
            'message' => 'Simulasi pembayaran berhasil dikonfirmasi.',
            'order' => $paidOrder,
        ]);
    }

    /**
     * Midtrans Webhook Handler for Crowd Buy payments.
     */
    public function webhook(Request $request, ProcessCrowdBuyPaymentAction $action): JsonResponse
    {
        $payload = $request->all();
        Log::info('CrowdBuy Midtrans Webhook received', $payload);

        $orderId = $payload['order_id'] ?? null;
        if (!$orderId) {
            return response()->json(['message' => 'Invalid payload: order_id is missing'], 400);
        }

        /** @var CrowdBuyPayment|null $payment */
        $payment = CrowdBuyPayment::where('external_id', $orderId)->first();
        if (!$payment) {
            // Might be standard invoice payment, not crowd buy
            return response()->json(['message' => 'CrowdBuy payment not found for order_id'], 404);
        }

        $transactionStatus = $payload['transaction_status'] ?? null;
        $fraudStatus = $payload['fraud_status'] ?? null;

        $isSettled = ($transactionStatus === 'capture' && $fraudStatus === 'accept')
            || $transactionStatus === 'settlement';

        if ($isSettled) {
            $order = $payment->order;
            if ($order) {
                $action->markAsPaid($order, $payload['transaction_id'] ?? $orderId, $payload);
            }
        } elseif (in_array($transactionStatus, ['cancel', 'deny', 'expire'])) {
            $payment->update([
                'status' => $transactionStatus,
                'raw_response' => array_merge($payment->raw_response ?? [], $payload),
            ]);
        }

        return response()->json(['message' => 'Webhook received and processed successfully.']);
    }
}
