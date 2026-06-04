<?php

namespace App\Domain\Order\Actions;

use App\Domain\Order\Repositories\OrderRepositoryInterface;
use App\Domain\Company\Models\Company;
use App\Domain\Order\Models\PurchaseOrder;
use App\Domain\Communication\Actions\BroadcastWebsocketNotificationAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConfirmPurchaseOrderAction
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly BroadcastWebsocketNotificationAction $broadcastAction
    ) {}

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
            // 1. Move PO status to confirmed
            $updateData = ['status' => 'confirmed'];

            // 2. Derive PO amount from the accepted proposal or negotiation
            $winningProposal = $this->orderRepository->findAcceptedProposal($po);
            $poAmount = $winningProposal ? $winningProposal->price_offer : ($po->total_amount ?? 0);

            // Update PO with winning proposal terms if not already set
            if ($winningProposal && !$po->purchase_type) {
                $updateData['purchase_type'] = $winningProposal->payment_term;
            }

            $po = $this->orderRepository->updatePurchaseOrder($po, $updateData);

            // 3. Release Proforma Invoice
            $this->orderRepository->createInvoice([
                'purchase_order_id' => $po->id,
                'type'              => 'proforma',
                'amount'            => $poAmount,
                'status'            => 'unpaid',
            ]);

            // Generate placeholder PDF for the proforma invoice
            $dummyPath = storage_path('app/public/invoices/dummy_proforma.pdf');
            $targetPath = storage_path("app/public/invoices/proforma_{$po->id}.pdf");
            if (file_exists($dummyPath)) {
                copy($dummyPath, $targetPath);
            }

            // 4. Notify the buyer about PO confirmation
            $this->broadcastAction->execute(
                "Purchase Order Confirmed",
                "Vendor {$vendorCompany->name} has confirmed Purchase Order {$po->po_number}.",
                'test-channel',
                true,
                $po->created_by, // Notify the buyer user who created the PO
                "/orders?search={$po->po_number}"
            );

            // 5. Notify the buyer about Proforma Invoice
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
