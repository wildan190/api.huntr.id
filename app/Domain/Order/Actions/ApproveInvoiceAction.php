<?php

namespace App\Domain\Order\Actions;

use App\Domain\Company\Models\Company;
use App\Domain\Order\Models\Invoice;
use App\Domain\Communication\Actions\BroadcastWebsocketNotificationAction;
use Illuminate\Validation\ValidationException;

use App\Domain\Payment\Services\MidtransIrisService;

class ApproveInvoiceAction
{
    public function __construct(
        private readonly BroadcastWebsocketNotificationAction $broadcastAction,
        private readonly MidtransIrisService $irisService
    ) {}

    /**
     * Finance team (Buyer) approves an invoice, moving it from pending_finance to unpaid.
     *
     * @param Company $buyerCompany
     * @param Invoice $invoice
     * @return Invoice
     * @throws ValidationException
     */
    public function execute(Company $buyerCompany, Invoice $invoice): Invoice
    {
        if ($invoice->purchaseOrder->buyer_company_id !== $buyerCompany->id) {
            throw ValidationException::withMessages([
                'buyer' => ['This invoice does not belong to your company.'],
            ]);
        }

        if ($invoice->status !== 'pending_finance') {
            throw ValidationException::withMessages([
                'invoice' => ['Only invoices pending finance approval can be approved.'],
            ]);
        }

        $po = $invoice->purchaseOrder;
        $vendor = $po->vendor;

        if (empty($vendor->bank_account) || empty($vendor->bank_name)) {
            throw ValidationException::withMessages([
                'vendor_bank' => ['Informasi rekening bank Vendor belum lengkap. Tidak dapat meneruskan dana (Disbursement).'],
            ]);
        }

        // 1. Mark as disbursing so UI knows it's processing
        $invoice->update(['status' => 'disbursing']);

        // 2. Prepare payload and dispatch job to Horizon
        $safePoNumber = preg_replace('/[^a-zA-Z0-9\s]/', '', $po->po_number);
        
        $payoutPayload = [
            'beneficiary_name' => $vendor->bank_account_name ?? $vendor->name,
            'beneficiary_account' => $vendor->bank_account,
            'beneficiary_bank' => strtolower($vendor->bank_name),
            'beneficiary_email' => $vendor->email,
            'amount' => number_format($invoice->amount, 2, '.', ''),
            'notes' => "Payout for PO {$safePoNumber}"
        ];

        $vendorUserIds = collect($vendor->users->pluck('id'))->push($vendor->owner_id)->unique()->filter()->toArray();

        \App\Domain\Payment\Jobs\ProcessIrisDisbursementJob::dispatch(
            $invoice->id,
            $po->po_number,
            $payoutPayload,
            $po->created_by,
            $vendorUserIds
        );

        return $invoice;
    }
}
