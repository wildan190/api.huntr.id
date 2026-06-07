<?php

namespace App\Domain\Order\Actions;

use App\Domain\Order\Repositories\OrderRepositoryInterface;
use App\Domain\Company\Models\Company;
use App\Domain\Order\Models\Invoice;
use App\Domain\Communication\Actions\BroadcastWebsocketNotificationAction;
use Illuminate\Validation\ValidationException;

class PublishInvoiceAction
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly BroadcastWebsocketNotificationAction $broadcastAction
    ) {}

    /**
     * Vendor manually publishes the final invoice to the buyer after goods are received.
     *
     * @param Company $vendorCompany
     * @param Invoice $invoice
     * @return Invoice
     * @throws ValidationException
     */
    public function execute(Company $vendorCompany, Invoice $invoice): Invoice
    {
        if ($invoice->purchaseOrder->vendor_id !== $vendorCompany->id) {
            throw ValidationException::withMessages([
                'vendor' => ['This invoice does not belong to your company.'],
            ]);
        }

        if ($invoice->type !== 'final') {
            throw ValidationException::withMessages([
                'invoice' => ['Only final invoices can be published. Proforma invoices are handled separately.'],
            ]);
        }

        if ($invoice->status !== 'draft') {
            throw ValidationException::withMessages([
                'invoice' => ['Only draft invoices can be published.'],
            ]);
        }

        // Set status to pending_finance so Buyer's finance team can review and approve
        $invoice->update(['status' => 'pending_finance']);

        $po = $invoice->purchaseOrder;

        // Notify Buyer — their finance team needs to review
        $this->broadcastAction->execute(
            "Invoice Menunggu Approval Finance",
            "Vendor telah menerbitkan Invoice Akhir untuk PO {$po->po_number}. Silahkan tim Finance Anda melakukan review & approval.",
            'test-channel',
            true,
            $po->created_by,
            "/finance?search={$po->po_number}"
        );

        return $invoice;
    }
}
