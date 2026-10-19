<?php

namespace App\Domain\Subscription\Support;

use App\Domain\Admin\Models\AdminSetting;

class EnterprisePlanCatalog
{
    private function overrides(): array
    {
        $value = AdminSetting::get('enterprise_pricing', '{}');
        return is_string($value) ? (json_decode($value, true) ?: []) : [];
    }

    public function find(string $key): ?array
    {
        foreach ($this->plans() as $plan) {
            if ($plan['key'] === $key) {
                return $plan;
            }
        }

        return null;
    }

    public function plans(?array $configuration = null): array
    {
        $plans = [
            [
                'key' => 'enterprise_starter',
                'name' => 'Enterprise Starter',
                'monthly_price' => 3800000,
                'annual_price' => $this->annualPrice(3800000),
                'users' => 15,
                'tax_documents_per_year' => 1000,
                'support' => 'Business Hours',
                'warehouses' => 1,
            ],
            [
                'key' => 'enterprise_growth',
                'name' => 'Enterprise Growth',
                'monthly_price' => 6200000,
                'annual_price' => $this->annualPrice(6200000),
                'users' => 35,
                'tax_documents_per_year' => 3000,
                'support' => 'Priority',
                'warehouses' => 2,
            ],
            [
                'key' => 'enterprise_pro',
                'name' => 'Enterprise Pro',
                'monthly_price' => 9500000,
                'annual_price' => $this->annualPrice(9500000),
                'users' => 70,
                'tax_documents_per_year' => 5000,
                'support' => 'Priority',
                'warehouses' => 10,
            ],
        ];
        $overrides = collect(($configuration ?? $this->overrides())['plans'] ?? [])->keyBy('key');
        return array_map(fn ($plan) => array_replace($plan, $overrides->get($plan['key'], [])), $plans);
    }

    public function addons(?array $configuration = null): array
    {
        $addons = [
            ['key' => 'tax_documents', 'name' => 'Top up dokumen pajak', 'price' => 500000, 'quantity' => 1000, 'unit' => 'dokumen', 'billing_cycle' => 'one_time'],
            ['key' => 'extra_user', 'name' => 'Ekstra pengguna', 'price' => 150000, 'quantity' => 1, 'unit' => 'pengguna', 'billing_cycle' => 'monthly'],
            ['key' => 'extra_warehouse', 'name' => 'Ekstra lokasi warehouse', 'price' => 500000, 'quantity' => 1, 'unit' => 'warehouse', 'billing_cycle' => 'monthly'],
        ];
        $overrides = collect(($configuration ?? $this->overrides())['addons'] ?? [])->keyBy('key');
        return array_map(fn ($addon) => array_replace($addon, $overrides->get($addon['key'], [])), $addons);
    }

    private function annualPrice(int $monthlyPrice): int
    {
        // Rp30m / (Rp3.8m × 12) = 25/38; round to whole rupiah.
        return intdiv($monthlyPrice * 12 * 25 + 19, 38);
    }
}
