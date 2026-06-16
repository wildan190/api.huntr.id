<?php

namespace App\Domain\Auth\Actions;

use App\Domain\Order\Models\Invoice;

class GetAdminEscrowSummaryAction
{
    public function execute(): array
    {
        $heldInvoicesQuery = Invoice::whereIn('status', ['pending_finance', 'disbursing']);

        return [
            'total_escrow_amount' => (clone $heldInvoicesQuery)->sum('amount'),
            'total_invoices_held' => (clone $heldInvoicesQuery)->count(),
            'recent_held_invoices' => (clone $heldInvoicesQuery)
                ->with(['purchaseOrder.buyer', 'purchaseOrder.vendor'])
                ->orderBy('created_at', 'desc')
                ->limit(5)
                ->get(),
        ];
    }
}
