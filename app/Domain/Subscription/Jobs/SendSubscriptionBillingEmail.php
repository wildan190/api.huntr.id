<?php

namespace App\Domain\Subscription\Jobs;

use App\Domain\Company\Models\Company;
use App\Domain\Subscription\Mail\SubscriptionBillingMail;
use App\Domain\Subscription\Models\SubscriptionCheckout;
use App\Domain\Subscription\Services\SubscriptionBillingEmailService;
use App\Domain\Subscription\Support\EnterprisePlanCatalog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class SendSubscriptionBillingEmail implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public array $backoff = [60, 300, 900, 1800];
    public int $uniqueFor = 7200;

    public function __construct(public readonly string $checkoutId) {}

    public function uniqueId(): string
    {
        return $this->checkoutId;
    }

    public function handle(EnterprisePlanCatalog $catalog, SubscriptionBillingEmailService $billingEmail): void
    {
        DB::transaction(function () use ($catalog, $billingEmail) {
            $checkout = SubscriptionCheckout::query()->whereKey($this->checkoutId)->lockForUpdate()->first();
            if (! $checkout || $checkout->billing_email_sent_at || ! in_array($checkout->status, ['pending', 'paid'], true)) {
                return;
            }
            $info = $checkout->payment_info ?? [];
            if ($checkout->status === 'pending' && empty($info['va_number']) && (empty($info['bill_key']) || empty($info['biller_code']))) {
                return;
            }
            $company = Company::with('owner')->findOrFail($checkout->company_id);
            $email = $checkout->billing_email ?: $billingEmail->address($company);
            $item = $checkout->item_snapshot ?: ($checkout->purchase_type === 'addon'
                ? collect($catalog->addons())->firstWhere('key', $checkout->addon_key)
                : ($checkout->purchase_type === 'addons' ? ['name' => 'Add-on Enterprise'] : $catalog->find($checkout->enterprise_plan)));
            $bill = [
                'company' => $company->name, 'order_id' => $checkout->order_id,
                'item' => $item['name'] ?? $checkout->enterprise_plan, 'quantity' => $checkout->quantity,
                'cycle' => $checkout->billing_cycle, 'amount' => $checkout->amount,
                'method' => $checkout->payment_method, 'payment_info' => $info,
                'paid' => $checkout->status === 'paid', 'addons' => $checkout->addons_snapshot ?? [],
            ];
            Mail::to($email)->send(new SubscriptionBillingMail($bill));
            $checkout->forceFill(['billing_email' => $email, 'billing_email_sent_at' => now()])->save();
        });
    }
}
