<?php

namespace App\Domain\Receipt\Actions;

use App\Domain\Receipt\Repositories\ReceiptRepositoryInterface;
use App\Domain\Order\Models\Invoice;
use App\Domain\Auth\Models\User;
use Illuminate\Validation\UnauthorizedException;

class ApproveFinalInvoiceAction
{
    public function __construct(
        private readonly ReceiptRepositoryInterface $receiptRepository
    ) {}

    /**
     * Finance user approves final invoice payment schema.
     *
     * @param User $finance The approving finance user
     * @param Invoice $invoice Target final invoice
     * @return Invoice
     * @throws UnauthorizedException
     */
    public function execute(User $finance, Invoice $invoice): Invoice
    {
        if (!$finance->hasRole('finance')) {
            throw new UnauthorizedException("Only finance officers can approve final payment schemas.");
        }

        $invoice->update(['status' => 'paid']);
        $invoice->purchaseOrder->update(['status' => 'done']);

        return $invoice;
    }
}
