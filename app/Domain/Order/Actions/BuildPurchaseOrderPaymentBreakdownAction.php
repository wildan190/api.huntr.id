<?php

namespace App\Domain\Order\Actions;

class BuildPurchaseOrderPaymentBreakdownAction
{
    public function __construct(
        private readonly CalculateInvoiceFeesAction $calculateInvoiceFeesAction,
    ) {}

    /**
     * Build a payment breakdown before an invoice has been issued.
     *
     * @return array{base_amount: float, admin_bank: float, pph23: float, ppn: float, total_amount: float}
     */
    public function execute(float $baseAmount): array
    {
        $fees = $this->calculateInvoiceFeesAction->execute($baseAmount);

        return [
            'base_amount' => $fees['base_amount'],
            'admin_bank' => $fees['midtrans_fee'],
            'pph23' => $fees['pph23'],
            'ppn' => $fees['ppn_fee'],
            'total_amount' => $fees['total_amount'],
        ];
    }
}
