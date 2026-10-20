<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $setting = DB::table('admin_settings')->where('key', 'enterprise_pricing')->first();

        if ($setting) {
            $configuration = json_decode($setting->value, true) ?: [];
            $plans = $configuration['plans'] ?? [];
            $changed = false;

            foreach ($plans as $index => $plan) {
                if (($plan['key'] ?? null) !== 'enterprise_pro') {
                    continue;
                }

                $plans[$index]['users'] = 200;
                $plans[$index]['warehouses'] = 100;
                $changed = true;
            }

            if ($changed) {
                $configuration['plans'] = $plans;
                DB::table('admin_settings')->where('key', 'enterprise_pricing')->update([
                    'value' => json_encode($configuration, JSON_THROW_ON_ERROR),
                    'updated_at' => now(),
                ]);
            }
        }

        DB::table('subscription_checkouts')
            ->where('enterprise_plan', 'enterprise_pro')
            ->whereIn('status', ['initiating', 'pending', 'unknown'])
            ->whereNotNull('item_snapshot')
            ->orderBy('id')
            ->each(function (object $checkout): void {
                $snapshot = json_decode($checkout->item_snapshot, true);

                if (! is_array($snapshot)) {
                    return;
                }

                $snapshot['users'] = 200;
                $snapshot['warehouses'] = 100;

                DB::table('subscription_checkouts')->where('id', $checkout->id)->update([
                    'item_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                    'updated_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        // Entitlements captured by completed subscriptions remain contractual snapshots.
    }
};
