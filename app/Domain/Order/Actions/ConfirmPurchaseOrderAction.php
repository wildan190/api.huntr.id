<?php

namespace App\Domain\Order\Actions;

use App\Domain\Order\Repositories\OrderRepositoryInterface;
use App\Domain\Company\Models\Company;
use App\Domain\Order\Models\PurchaseOrder;
use App\Domain\Communication\Actions\BroadcastWebsocketNotificationAction;
use App\Domain\Subscription\Actions\ResolveSubscriptionBillingAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConfirmPurchaseOrderAction
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly BroadcastWebsocketNotificationAction $broadcastAction,
        private readonly CalculateInvoiceFeesAction $calculateInvoiceFeesAction,
        private readonly ResolveSubscriptionBillingAction $resolveSubscriptionBillingAction,
    ) {
    }

    /**
     * Vendor confirms the generated Purchase Order, releasing the Proforma Invoice.
     *
     * @param Company $vendorCompany Target vendor company
     * @param PurchaseOrder $po Target PO
     * @return PurchaseOrder
     * @throws ValidationException
     */
    public function execute(Company $vendorCompany, PurchaseOrder $po): PurchaseOrder
    {
        if ($po->vendor_id !== $vendorCompany->id) {
            throw ValidationException::withMessages([
                'vendor' => ['This PO does not belong to your company.'],
            ]);
        }

        return DB::transaction(function () use ($vendorCompany, $po) {

            $updateData = ['status' => 'confirmed'];

            $winningProposal = $this->orderRepository->findAcceptedProposal($po);
            $poAmount = $winningProposal ? $winningProposal->price_offer : ($po->total_amount ?? 0);

            $billing = $this->resolveSubscriptionBillingAction->execute($po->buyer, (float) $poAmount);
            $fees = $this->calculateInvoiceFeesAction->execute(
                (float) $poAmount,
                $po->buyer,
                $billing['waive_platform_fee'],
            );

            if ($winningProposal && !$po->purchase_type) {
                $updateData['purchase_type'] = $winningProposal->payment_term;
            }

            $timeline = $po->tracking_timeline ?? [];
            $timeline[] = [
                'status' => 'confirmed',
                'label' => 'PO Confirmed',
                'timestamp' => now()->toIso8601String(),
                'actor_name' => $vendorCompany->name,
                'actor_type' => 'vendor',
                'note' => null,
            ];
            $updateData['tracking_timeline'] = $timeline;

            $po = $this->orderRepository->updatePurchaseOrder($po, $updateData);

            $this->orderRepository->createInvoice([
                'purchase_order_id' => $po->id,
                'company_subscription_id' => $billing['subscription_id'],
                'type' => 'proforma',
                'amount' => $fees['total_amount'],
                'base_amount' => $fees['base_amount'],
                'billing_mode' => $billing['billing_mode'],
                'gmv_credited_amount' => $billing['gmv_credited_amount'],
                'platform_fee' => $fees['platform_fee'],
                'ppn_platform' => $fees['ppn_platform'],
                'midtrans_fee' => $fees['midtrans_fee'],
                'pph23' => $fees['pph23'],
                'ppn_fee' => $fees['ppn_fee'],
                'total_amount' => $fees['total_amount'],
                'status' => 'unpaid',

                'vendor_signed_name' => $vendorCompany->name,
                'vendor_signed_at' => now(),
            ]);

            $dummyPath = storage_path('app/public/invoices/dummy_proforma.pdf');
            $targetPath = storage_path("app/public/invoices/proforma_{$po->id}.pdf");
            if (file_exists($dummyPath)) {
                copy($dummyPath, $targetPath);
            }

            $this->broadcastAction->execute(
                "Purchase Order Confirmed",
                "Vendor {$vendorCompany->name} has confirmed Purchase Order {$po->po_number}.",
                'test-channel',
                true,
                $po->created_by,
                "/orders?search={$po->po_number}"
            );

            $this->broadcastAction->execute(
                "Proforma Invoice Published",
                "A Proforma Invoice has been published for PO {$po->po_number}. Please review and process payment.",
                'test-channel',
                true,
                $po->created_by,
                "/orders?search={$po->po_number}"
            );

            return $po;
        });
    }
}
