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

        // Payment scheme release date check
        $paymentScheme = null;
        if ($po->rfq) {
            $winningProposal = $po->rfq->proposals->where('status', 'accepted')->first();
            if ($winningProposal && $winningProposal->acceptedNegotiation && !empty($winningProposal->acceptedNegotiation->payment_scheme)) {
                $paymentScheme = $winningProposal->acceptedNegotiation->payment_scheme;
            } else if ($winningProposal && !empty($winningProposal->payment_term)) {
                $paymentScheme = $winningProposal->payment_term;
            }
        }
        $paymentScheme = $paymentScheme ?? ($po->purchase_type !== 'N/A' ? $po->purchase_type : null);

        if ($paymentScheme) {
            preg_match('/\d+/', strtolower($paymentScheme), $matches);
            $days = isset($matches[0]) ? (int)$matches[0] : 0;
            $lowerScheme = strtolower($paymentScheme);
            if (str_contains($lowerScheme, 'cbd') || str_contains($lowerScheme, 'cod') || str_contains($lowerScheme, 'cash')) {
                $days = 0;
            }
            
            $invDate = $invoice->created_at;
            $dueDate = $invDate->copy()->addDays($days)->startOfDay();
            
            if (now()->startOfDay()->lessThan($dueDate)) {
                throw ValidationException::withMessages([
                    'invoice' => ["Approval belum diizinkan sesuai Payment Scheme ({$paymentScheme}). Invoice baru dapat di-approve pada tanggal {$dueDate->format('d M Y')}"],
                ]);
            }
        }

        $vendor = $po->vendor;

        if (empty($vendor->bank_account) || empty($vendor->bank_name)) {
            throw ValidationException::withMessages([
                'vendor_bank' => ['Vendor bank account information is incomplete. Cannot proceed with disbursement.'],
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
            'amount' => number_format($invoice->base_amount ?: $invoice->amount, 2, '.', ''),
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
