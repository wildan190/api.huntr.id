<?php

namespace App\Domain\Subscription\Actions;

use App\Domain\Order\Models\Invoice;
use App\Domain\Subscription\Models\CompanySubscription;
use Illuminate\Support\Facades\DB;

/** Records paid GMV once; safe to call from duplicated payment webhooks. */
class RecordRealizedGmvAction
{
    public function execute(Invoice $invoice): void
    {
        if ($invoice->billing_mode !== 'subscription_quota' || ! $invoice->company_subscription_id || $invoice->gmv_consumed_at) {
            return;
        }

        DB::transaction(function () use ($invoice): void {
            $lockedInvoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if ($lockedInvoice->gmv_consumed_at) {
                return;
            }

            $subscription = CompanySubscription::query()
                ->lockForUpdate()
                ->find($lockedInvoice->company_subscription_id);

            if (! $subscription) {
                return;
            }

            $creditedAmount = (float) $lockedInvoice->gmv_credited_amount;
            $subscription->update([
                'current_realized_gmv' => (float) $subscription->current_realized_gmv + $creditedAmount,
                'reserved_gmv' => max(0, (float) $subscription->reserved_gmv - $creditedAmount),
            ]);

            $lockedInvoice->update(['gmv_consumed_at' => now()]);
        });
    }
}
