<?php

namespace App\Domain\Subscription\Services;

use App\Domain\Subscription\Support\EnterprisePlanCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdminEnterprisePricingService
{
    public function __construct(private EnterprisePlanCatalog $catalog) {}

    public function get(): array
    {
        $value = DB::table('admin_settings')->where('key', 'enterprise_pricing')->value('value') ?? '{}';
        $configuration = json_decode($value, true) ?: [];
        return ['version' => hash('sha256', $value), 'plans' => $this->catalog->plans($configuration), 'addons' => $this->catalog->addons($configuration)];
    }

    public function update(array $data, int $adminId): array
    {
        DB::transaction(function () use ($data, $adminId) {
            $row = DB::table('admin_settings')->where('key', 'enterprise_pricing')->lockForUpdate()->first();
            abort_unless($row, 503, 'Migrasi konfigurasi Enterprise belum dijalankan.');
            if (! hash_equals(hash('sha256', $row->value), $data['version'])) {
                throw ValidationException::withMessages(['version' => 'Konfigurasi telah diubah admin lain. Muat ulang sebelum menyimpan.']);
            }
            $newValue = json_encode(['plans' => $data['plans'], 'addons' => $data['addons']], JSON_THROW_ON_ERROR);
            DB::table('enterprise_pricing_audits')->insert([
                'id' => (string) Str::uuid(), 'admin_id' => $adminId,
                'before' => $row->value, 'after' => $newValue, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('admin_settings')->where('key', 'enterprise_pricing')->update(['value' => $newValue, 'updated_at' => now()]);
        });
        return $this->get();
    }
}
