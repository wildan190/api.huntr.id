<?php

namespace App\Domain\Subscription\Services;

use App\Domain\Company\Models\Company;
use App\Domain\Subscription\Jobs\SendSubscriptionBillingEmail;
use App\Domain\Subscription\Models\SubscriptionCheckout;
use Illuminate\Validation\ValidationException;

class SubscriptionBillingEmailService
{
    public function address(Company $company): string
    {
        foreach ([$company->email, $company->owner?->email] as $email) {
            if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $email;
            }
        }
        throw ValidationException::withMessages(['email' => 'Daftarkan email perusahaan atau pemilik yang valid untuk menerima tagihan.']);
    }

    public function enqueue(SubscriptionCheckout $checkout): void
    {
        if ($checkout->billing_email_sent_at || ! in_array($checkout->status, ['pending', 'paid'], true)) {
            return;
        }
        $info = $checkout->payment_info ?? [];
        if ($checkout->status === 'pending' && empty($info['va_number']) && (empty($info['bill_key']) || empty($info['biller_code']))) {
            return;
        }
        try {
            SendSubscriptionBillingEmail::dispatch($checkout->id)->afterCommit();
        } catch (\Throwable $e) {
            // Payment state is independent of mail queue availability. The scheduler retries.
            report($e);
        }
    }

    public function enqueueOutstanding(): void
    {
        SubscriptionCheckout::query()->whereNull('billing_email_sent_at')
            ->whereIn('status', ['pending', 'paid'])->whereNotNull('payment_info')
            ->chunkById(100, fn ($checkouts) => $checkouts->each(fn ($checkout) => $this->enqueue($checkout)));
    }
}
