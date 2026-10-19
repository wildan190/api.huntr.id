<?php

namespace App\Domain\Subscription\Services;

use App\Domain\Company\Models\Company;
use App\Domain\Subscription\Models\SubscriptionCheckout;
use App\Domain\Subscription\Support\EnterprisePlanCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CheckoutAddonService
{
    public function __construct(private EnterprisePlanCatalog $catalog, private TaxQuotaService $taxQuota) {}

    public function snapshot(array $selections): array
    {
        $catalog = collect($this->catalog->addons())->keyBy('key');
        return collect($selections)->map(function (array $selection) use ($catalog) {
            $addon = $catalog->get($selection['key']);
            if (! $addon) {
                throw ValidationException::withMessages(['addons' => 'Add-on tidak tersedia.']);
            }
            return [
                'key' => $addon['key'], 'name' => $addon['name'], 'price' => (int) $addon['price'],
                'units_per_pack' => (int) $addon['quantity'], 'unit' => $addon['unit'],
                'billing_cycle' => $addon['billing_cycle'], 'quantity' => (int) $selection['quantity'],
            ];
        })->sortBy('key')->values()->all();
    }

    public function total(array $addons): int
    {
        return array_sum(array_map(fn ($addon) => $addon['price'] * $addon['quantity'], $addons));
    }

    /** Called while the company and checkout are locked in the payment transaction. */
    public function grant(Company $company, SubscriptionCheckout $checkout, array $addons): void
    {
        foreach ($addons as $addon) {
            $period = $addon['key'] === 'tax_documents' ? $this->taxQuota->period($company) : null;
            $now = now();
            DB::table('company_subscription_addons')->insert([
                'id' => (string) Str::uuid(), 'company_id' => $company->id,
                'subscription_checkout_id' => $checkout->id, 'addon_key' => $addon['key'],
                'quantity' => $addon['quantity'] * $addon['units_per_pack'],
                'starts_at' => $now, 'ends_at' => $period ? $period->ends_at : $now->copy()->addMonthNoOverflow(),
                'tax_quota_period_id' => $period?->id, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }
}
