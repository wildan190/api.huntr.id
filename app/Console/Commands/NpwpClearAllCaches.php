<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Redis;

class NpwpClearAllCaches extends Command
{
    protected $signature = 'npwp:clear-all-caches';
    protected $description = '[CLEAN VSWP] Bersihkan SEMUA cache terkait NPWP: config cache, route cache, Redis pajakexpress_npwp_*, PajakExpress auth token.';

    public function handle()
    {
        $this->warn('╔══════════════════════════════════════════════════════════════════╗');
        $this->warn('║  🧹 CLEAR ALL NPWP / VSWP RELATED CACHES                      ║');
        $this->warn('╚══════════════════════════════════════════════════════════════════╝');

        $steps = [];

        $this->info("\n[1/8] Bersihkan Config Cache (Laravel bootstrap/cache/config.php)");
        try {
            Artisan::call('config:clear');
            $output = trim(Artisan::output());
            $this->line("   ✅ {$output}");
            $steps[] = ['config:clear', 'OK', $output];
        } catch (\Throwable $e) {
            $steps[] = ['config:clear', 'FAIL', $e->getMessage()];
            $this->error("   ❌ " . $e->getMessage());
        }

        $this->info("\n[2/8] Bersihkan App Cache (default store / Redis)");
        try {
            Artisan::call('cache:clear');
            $output = trim(Artisan::output());
            $this->line("   ✅ {$output}");
            $steps[] = ['cache:clear', 'OK', $output];
        } catch (\Throwable $e) {
            $steps[] = ['cache:clear', 'FAIL', $e->getMessage()];
            $this->error("   ❌ " . $e->getMessage());
        }

        $this->info("\n[3/8] Hapus spesifik cache Auth Token PajakExpress (pajakexpress_auth_token)");
        $authKey = \App\Domain\Company\Services\PajakExpressService::AUTH_CACHE_KEY;
        if (Cache::has($authKey)) {
            Cache::forget($authKey);
            $this->line("   ✅ Cache auth token Dihapus.");
            $steps[] = ['pajakexpress_auth_token', 'DELETED', 'cache key removed'];
        } else {
            $this->line("   ⏭ Cache auth token tidak ada (skip).");
            $steps[] = ['pajakexpress_auth_token', 'N/A', 'cache key absent'];
        }

        $this->info("\n[4/8] SCAN Redis untuk pattern pajakexpress_npwp_* dan pajakio_npwp_*");
        $deletedCount = 0;
        try {
            if (config('cache.default') === 'redis') {
                $redisConnection = config('cache.stores.redis.connection', 'default');
                $redis = Redis::connection($redisConnection);
                $prefix = config('cache.prefix', '');

                foreach (['pajakexpress_npwp_*', 'pajakio_npwp_*'] as $pattern) {
                    $keys = $redis->keys($prefix . $pattern);
                    $count = is_array($keys) ? count($keys) : 0;
                    if ($count > 0) {
                        foreach ($keys as $fullKey) {
                            $rawKey = is_string($fullKey) ? substr($fullKey, strlen($prefix)) : $fullKey;
                            Cache::forget($rawKey);
                            $deletedCount++;
                        }
                        $this->line("   ✅ Pattern {$pattern}: {$count} key DITEMUKAN & DIHAPUS");
                        $steps[] = ['cache:pattern:' . $pattern, 'DELETED', "{$count} keys"];
                    } else {
                        $this->line("   ⏭ Pattern {$pattern}: tidak ditemukan.");
                        $steps[] = ['cache:pattern:' . $pattern, 'N/A', '0 keys'];
                    }
                }
            } else {
                $this->line("   ⚠ Cache store bukan Redis, skip specific delete (pakai cache:clear saja).");
                $steps[] = ['cache:pattern scan', 'SKIP', 'store=' . config('cache.default')];
            }
        } catch (\Throwable $e) {
            $this->error("   ❌ Exception: " . $e->getMessage());
            $steps[] = ['cache:pattern scan', 'FAIL', $e->getMessage()];
        }

        $this->info("\n[5/8] Bersihkan Route Cache (jika ada)");
        try {
            Artisan::call('route:clear');
            $output = trim(Artisan::output());
            $this->line("   ✅ {$output}");
            $steps[] = ['route:clear', 'OK', $output];
        } catch (\Throwable $e) {
            $steps[] = ['route:clear', 'FAIL', $e->getMessage()];
        }

        $this->info("\n[6/8] Bersihkan View Cache");
        try {
            Artisan::call('view:clear');
            $output = trim(Artisan::output());
            $this->line("   ✅ {$output}");
            $steps[] = ['view:clear', 'OK', $output];
        } catch (\Throwable $e) {
            $steps[] = ['view:clear', 'FAIL', $e->getMessage()];
        }

        $this->info("\n[7/8] Bersihkan Event / Queue Listeners Cache");
        try {
            if (method_exists(Artisan::class, 'call') && $this->commandExists('event:clear')) {
                Artisan::call('event:clear');
                $output = trim(Artisan::output());
                $this->line("   ✅ {$output}");
                $steps[] = ['event:clear', 'OK', $output];
            } else {
                $this->line("   ⏭ Command event:clear tidak ada, skip.");
                $steps[] = ['event:clear', 'N/A', 'skip'];
            }
        } catch (\Throwable $e) {
            $steps[] = ['event:clear', 'FAIL', $e->getMessage()];
        }

        $this->info("\n[8/8] Clear Compiled Class Cache (optimize:clear)");
        try {
            Artisan::call('optimize:clear');
            $output = trim(Artisan::output());
            $this->line("   ✅ {$output}");
            $steps[] = ['optimize:clear', 'OK', $output];
        } catch (\Throwable $e) {
            $steps[] = ['optimize:clear', 'FAIL', $e->getMessage()];
        }

        $this->warn("\n═══════════════════════════════════════════════════════════════════");
        $this->info("📋 RINGKASAN EKSEKUSI:");
        $this->table(['Step', 'Status', 'Detail'], $steps);

        $this->warn("\n⚠️  JIKA PAKAI LARAVEL OCTANE / SERVER LONG-RUNNING (RoadRunner/Swoole):");
        $this->line('   Class cache di MEMORY — Reload / restart Octane WAJIB:');
        $this->line('   • php artisan octane:reload');
        $this->line('   • ATAU restart container: docker compose restart api_app_worker api');
        $this->line('   • ATAU php-fpm restart (jika pakai FPM / Nginx)');

        $this->warn("\n⚠️  JIKA PAKAI DOCKER COMPOSE:");
        $this->line('   Pastikan file .env.local DIMOUNTING ke container sebagai .env.');
        $this->line('   Jalankan perintah artisan di DALAM container (docker compose exec api php artisan ...).');

        $this->warn("\n🚀 SELESAI. Sekarang JALANKAN UNTUK VERIFIKASI:");
        $this->line('   php artisan npwp:debug-pajak-express-raw --npwp=01.123.456.7-012.345');

        return 0;
    }

    private function commandExists(string $name): bool
    {
        return array_key_exists($name, Artisan::all());
    }
}
