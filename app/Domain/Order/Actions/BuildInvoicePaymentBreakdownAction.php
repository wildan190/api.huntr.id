<?php

namespace App\Domain\Order\Actions;

use App\Domain\Order\Models\Invoice;

class BuildInvoicePaymentBreakdownAction
{
    public function __construct(
        private readonly BuildPurchaseOrderPaymentBreakdownAction $buildPurchaseOrderPaymentBreakdownAction,
    ) {}

    /**
     * Return the payable PO components without any platform or service fee.
     *
     * Stored invoice values take precedence so changing global tax settings
     * never changes an issued invoice. Defaults support older invoices that
     * did not store these components.
     *
     * @return array{base_amount: float, admin_bank: float, pph23: float, ppn: float, total_amount: float}
     */
    public function execute(Invoice $invoice, ?float $fallbackBaseAmount = null): array
    {
        $baseAmount = $this->resolveBaseAmount($invoice, $fallbackBaseAmount);
        $defaults = $this->buildPurchaseOrderPaymentBreakdownAction->execute($baseAmount);
        $adminBank = $this->storedOrDefault((float) $invoice->midtrans_fee, $defaults['admin_bank']);
        $ppn = $this->storedOrDefault((float) $invoice->ppn_fee, $defaults['ppn']);
        $pph23 = $this->storedOrDefault((float) $invoice->pph23, $defaults['pph23']);

        return [
            'base_amount' => $baseAmount,
            'admin_bank' => $adminBank,
            'pph23' => $pph23,
            'ppn' => $ppn,
            'total_amount' => round($baseAmount + $adminBank + $ppn - $pph23, 2),
        ];
    }

    private function resolveBaseAmount(Invoice $invoice, ?float $fallbackBaseAmount): float
    {
        $invoiceAmount = (float) ($invoice->base_amount ?: $invoice->amount);
        if ($invoiceAmount > 0) {
            return $invoiceAmount;
        }

        $purchaseOrder = $invoice->purchaseOrder;
        $proforma = $purchaseOrder->relationLoaded('invoices')
            ? $purchaseOrder->invoices->firstWhere('type', 'proforma')
            : $purchaseOrder->invoices()->where('type', 'proforma')->latest()->first();
        $proformaAmount = (float) ($proforma?->base_amount ?: $proforma?->amount);

        if ($proformaAmount > 0) {
            return $proformaAmount;
        }

        $purchaseOrderAmount = (float) $purchaseOrder->total_amount;

        return $purchaseOrderAmount > 0 ? $purchaseOrderAmount : (float) $fallbackBaseAmount;
    }

    private function storedOrDefault(float $storedValue, float $default): float
    {
        return $storedValue > 0 ? $storedValue : $default;
    }
}
