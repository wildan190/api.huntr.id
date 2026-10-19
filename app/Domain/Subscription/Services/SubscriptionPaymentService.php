<?php

namespace App\Domain\Subscription\Services;

use App\Domain\Company\Models\Company;
use App\Domain\Payment\Services\MidtransService;
use App\Domain\Payment\Exceptions\MidtransChargeRejectedException;
use App\Domain\Subscription\Models\CompanySubscription;
use App\Domain\Subscription\Models\SubscriptionCheckout;
use App\Domain\Subscription\Support\EnterprisePlanCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SubscriptionPaymentService
{
    public function __construct(
        private MidtransService $midtrans,
        private EnterprisePlanCatalog $catalog,
        private CheckoutAddonService $addons,
        private SubscriptionBillingEmailService $billingEmail,
    ) {}

    public function create(Company $company, array $data): SubscriptionCheckout
    {
        abort_unless(config('enterprise_subscription.checkout_enabled', false), 503, 'Pembayaran paket enterprise belum tersedia.');
        abort_unless($company->type === 'buyer', 422, 'Vendor tidak dikenakan biaya subscription.');
        $isAddon = $data['purchase_type'] === 'addon';
        $isAddons = $data['purchase_type'] === 'addons';
        $isAddonPurchase = $isAddon || $isAddons;
        $selected = $isAddon
            ? collect($this->catalog->addons())->firstWhere('key', $data['addon_key'])
            : ($isAddons ? null : $this->catalog->find($data['plan']));
        if (! $isAddons && ! $selected) {
            throw ValidationException::withMessages(['plan' => 'Paket atau add-on tidak tersedia.']);
        }
        $addonSnapshot = $isAddon ? [] : $this->addons->snapshot($data['addons'] ?? []);
        $amount = $isAddon
            ? $selected['price'] * (int) $data['quantity']
            : ($isAddons ? 0 : $selected[$data['billing_cycle'] === 'annual' ? 'annual_price' : 'monthly_price']) + $this->addons->total($addonSnapshot);

        [$checkout, $created] = DB::transaction(function () use ($company, $data, $amount, $isAddon, $isAddons, $isAddonPurchase, $selected, $addonSnapshot) {
            Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
            $pending = SubscriptionCheckout::query()->where('company_id', $company->id)
                ->whereIn('status', ['initiating', 'pending', 'unknown'])->latest()->first();
            if ($pending) {
                if ($pending->purchase_type !== $data['purchase_type'] ||
                    ($isAddon && ($pending->addon_key !== $data['addon_key'] || $pending->quantity !== (int) $data['quantity'])) ||
                    (! $isAddonPurchase && ($pending->enterprise_plan !== $data['plan'] || $pending->billing_cycle !== $data['billing_cycle'])) ||
                    (! $isAddon && collect($pending->addons_snapshot ?? [])->map(fn ($item) => [$item['key'], (int) $item['quantity']])->all() !== collect($addonSnapshot)->map(fn ($item) => [$item['key'], (int) $item['quantity']])->all())) {
                    throw ValidationException::withMessages(['payment' => 'Selesaikan pembayaran subscription yang masih tertunda terlebih dahulu.']);
                }
                return [$pending, false];
            }

            $pricing = DB::table('admin_settings')->where('key', 'enterprise_pricing')->lockForUpdate()->first();
            abort_unless($pricing, 503, 'Migrasi konfigurasi Enterprise belum dijalankan.');
            if (! hash_equals(hash('sha256', $pricing->value), $data['catalog_version'])) {
                throw ValidationException::withMessages(['catalog_version' => 'Harga telah berubah. Muat ulang halaman dan periksa harga terbaru.']);
            }

            $active = CompanySubscription::query()->where('company_id', $company->id)
                ->where('status', 'active')->where('starts_at', '<=', now())->where('ends_at', '>', now())->first();
            if (! $isAddonPurchase && $active) {
                throw ValidationException::withMessages(['plan' => 'Perusahaan masih memiliki subscription aktif. Hubungi tim Huntr untuk perubahan paket.']);
            }
            if ($isAddonPurchase && (! $active || ! $active->enterprise_plan)) {
                throw ValidationException::withMessages(['addon_key' => 'Add-on memerlukan paket Enterprise yang aktif.']);
            }

            return [SubscriptionCheckout::create([
                'company_id' => $company->id,
                'order_id' => 'SUB-'.Str::upper(Str::random(24)),
                'purchase_type' => $data['purchase_type'],
                'enterprise_plan' => $isAddonPurchase ? $active->enterprise_plan : $data['plan'],
                'billing_cycle' => $isAddon ? ($data['addon_key'] === 'tax_documents' ? 'one_time' : 'monthly') : ($isAddons ? 'one_time' : $data['billing_cycle']),
                'addon_key' => $isAddon ? $data['addon_key'] : null,
                'quantity' => $isAddon ? $data['quantity'] : 1,
                'amount' => $amount,
                'payment_method' => $data['payment_method'],
                'status' => 'initiating',
                'billing_email' => $this->billingEmail->address($company),
                'item_snapshot' => $selected,
                'addons_snapshot' => $addonSnapshot,
            ]), true];
        });

        if (! $created) {
            return $checkout;
        }

        $items = [];
        if ($selected) {
            $items[] = ['id' => $selected['key'], 'price' => $isAddon ? $amount : $amount - $this->addons->total($addonSnapshot), 'quantity' => 1, 'name' => $selected['name']];
        }
        foreach ($addonSnapshot as $addon) {
            $items[] = ['id' => $addon['key'], 'price' => $addon['price'], 'quantity' => $addon['quantity'], 'name' => $addon['name']];
        }
        $label = $selected['name'] ?? 'Add-on Enterprise';
        $payload = [
            'transaction_details' => ['order_id' => $checkout->order_id, 'gross_amount' => $checkout->amount],
            'item_details' => $items,
            'customer_details' => ['first_name' => $company->name, 'email' => $company->email ?: $company->owner?->email],
        ];
        if ($data['payment_method'] === 'mandiri_va') {
            $payload['payment_type'] = 'echannel';
            $payload['echannel'] = ['bill_info1' => 'Subscription Huntr', 'bill_info2' => $label];
        } else {
            $payload['payment_type'] = 'bank_transfer';
            $payload['bank_transfer'] = ['bank' => str_replace('_va', '', $data['payment_method'])];
        }

        try {
            $response = $this->midtrans->charge($payload);
            DB::transaction(function () use ($checkout, $response) {
                $locked = SubscriptionCheckout::query()->lockForUpdate()->findOrFail($checkout->id);
                $locked->payment_info = [
                    'va_number' => $response['va_numbers'][0]['va_number'] ?? $response['permata_va_number'] ?? null,
                    'bill_key' => $response['bill_key'] ?? null,
                    'biller_code' => $response['biller_code'] ?? null,
                    'expiry_time' => $response['expiry_time'] ?? null,
                ];
                $locked->provider_response = $response;
                if ($locked->status === 'initiating') {
                    $locked->status = 'pending';
                }
                $locked->save();
            });
        } catch (MidtransChargeRejectedException $e) {
            SubscriptionCheckout::query()->whereKey($checkout->id)->where('status', 'initiating')->update(['status' => 'failure']);
            report($e);
        } catch (\Throwable $e) {
            // A timeout may occur after Midtrans accepted the charge. Do not
            // issue another order until its status has been reconciled.
            SubscriptionCheckout::query()->whereKey($checkout->id)->where('status', 'initiating')->update(['status' => 'unknown']);
            report($e);
        }

        $checkout = $checkout->fresh();
        $this->billingEmail->enqueue($checkout);
        return $checkout;
    }

    public function refresh(SubscriptionCheckout $checkout): SubscriptionCheckout
    {
        if (in_array($checkout->status, ['initiating', 'pending', 'unknown'], true)) {
            $response = $this->midtrans->getStatus($checkout->order_id);
            if (($response['order_id'] ?? null) === $checkout->order_id && isset($response['transaction_status'])) {
                $this->applyStatus($response);
            }
        }

        $checkout = $checkout->fresh();
        $this->billingEmail->enqueue($checkout);
        return $checkout;
    }

    public function acceptWebhook(array $payload): void
    {
        $key = preg_replace('/\s+/', '', str_replace(['"', "'"], '', (string) config('services.midtrans.server_key')));
        $orderId = (string) ($payload['order_id'] ?? '');
        $statusCode = (string) ($payload['status_code'] ?? '');
        $grossAmount = (string) ($payload['gross_amount'] ?? '');
        $signature = (string) ($payload['signature_key'] ?? '');
        abort_unless($key && $orderId && $statusCode && $grossAmount && $signature && hash_equals(hash('sha512', $orderId.$statusCode.$grossAmount.$key), $signature), 403, 'Signature Midtrans tidak valid.');
        $this->applyStatus($payload);
    }

    private function applyStatus(array $payload): void
    {
        DB::transaction(function () use ($payload) {
            $checkout = SubscriptionCheckout::query()->where('order_id', $payload['order_id'])->lockForUpdate()->firstOrFail();
            $amount = (string) ($payload['gross_amount'] ?? '');
            abort_unless(preg_match('/^(\d+)(?:\.0+)?$/', $amount, $matches) && (int) $matches[1] === $checkout->amount, 422, 'Nominal pembayaran tidak sesuai.');
            if ($checkout->status === 'paid') {
                return;
            }

            $status = $payload['transaction_status'] ?? '';
            $paymentInfo = $checkout->payment_info ?? [];
            foreach (['va_number' => $payload['va_numbers'][0]['va_number'] ?? $payload['permata_va_number'] ?? null,
                'bill_key' => $payload['bill_key'] ?? null, 'biller_code' => $payload['biller_code'] ?? null,
                'expiry_time' => $payload['expiry_time'] ?? null] as $key => $value) {
                if ($value !== null) {
                    $paymentInfo[$key] = $value;
                }
            }
            $checkout->payment_info = $paymentInfo;
            $settled = $status === 'settlement' || ($status === 'capture' && ($payload['fraud_status'] ?? '') === 'accept');
            if ($settled) {
                $company = Company::query()->whereKey($checkout->company_id)->lockForUpdate()->firstOrFail();
                if (in_array($checkout->purchase_type, ['addon', 'addons'], true)) {
                    $addons = $checkout->purchase_type === 'addon' ? [[
                        'key' => $checkout->addon_key, 'quantity' => $checkout->quantity,
                        'units_per_pack' => $checkout->addon_key === 'tax_documents' ? 1000 : 1,
                    ]] : ($checkout->addons_snapshot ?? []);
                    $this->addons->grant($company, $checkout, $addons);
                    $checkout->status = 'paid';
                    $checkout->provider_response = $payload;
                    $checkout->save();
                    return;
                }
                $existing = CompanySubscription::query()->where('company_id', $checkout->company_id)
                    ->where('status', 'active')->where('ends_at', '>', now())->orderByDesc('ends_at')->lockForUpdate()->first();
                $start = $existing ? $existing->ends_at->copy() : now();
                $subscription = CompanySubscription::create([
                    'company_id' => $checkout->company_id,
                    'plan' => 'saas_subscription',
                    'enterprise_plan' => $checkout->enterprise_plan,
                    'billing_cycle' => $checkout->billing_cycle,
                    'plan_snapshot' => $checkout->item_snapshot ?: $this->catalog->find($checkout->enterprise_plan),
                    'status' => 'active',
                    'overflow_strategy' => CompanySubscription::OVERFLOW_TRANSACTION_FEE,
                    'upfront_fee' => $checkout->amount - $this->addons->total($checkout->addons_snapshot ?? []),
                    'gmv_limit' => 0,
                    'starts_at' => $start,
                    'ends_at' => $checkout->billing_cycle === 'annual' ? $start->copy()->addYear() : $start->copy()->addMonthNoOverflow(),
                ]);
                $checkout->company_subscription_id = $subscription->id;
                $this->addons->grant($company, $checkout, $checkout->addons_snapshot ?? []);
                $checkout->status = 'paid';
            } elseif (in_array($status, ['deny', 'cancel', 'expire', 'failure'], true)) {
                $checkout->status = $status;
            } elseif ($status === 'pending') {
                $checkout->status = 'pending';
            }
            $checkout->provider_response = $payload;
            $checkout->save();
        });
        $checkout = SubscriptionCheckout::query()->where('order_id', $payload['order_id'])->first();
        if ($checkout) {
            $this->billingEmail->enqueue($checkout);
        }
    }
}
