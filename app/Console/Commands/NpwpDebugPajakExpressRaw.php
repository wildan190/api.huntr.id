<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Config;

class NpwpDebugPajakExpressRaw extends Command
{
    protected $signature = 'npwp:debug-pajak-express-raw
                            {--email= : Override PajakExpress login email}
                            {--password= : Override PajakExpress login password}
                            {--base-url= : Override PajakExpress base URL}
                            {--npwp= : NPWP untuk test verify endpoint (opsional)}';
    protected $description = '[DEBUG VSWP] Raw HTTP test PajakExpress /auth/login + /IF_CLB_059 verify — bypass service class, lihat RAW response';

    public function handle()
    {
        $baseUrl = rtrim($this->option('base-url') ?: Config::get('services.pajak_express.base_url', env('PAJAK_EXPRESS_BASE_URL')), '/');
        $email = (string)($this->option('email') ?: Config::get('services.pajak_express.email', env('PAJAK_EXPRESS_EMAIL')));
        $password = (string)($this->option('password') ?: Config::get('services.pajak_express.password', env('PAJAK_EXPRESS_PASSWORD')));
        $npwp = $this->option('npwp') ?: '01.234.567.8-901.234';

        $this->warn('╔══════════════════════════════════════════════════════════════╗');
        $this->warn('║  [DEBUG RAW] PAJAK EXPRESS VSWP - NO SERVICE WRAPPER       ║');
        $this->warn('╚══════════════════════════════════════════════════════════════╝');

        $this->info("\n📋 [0/2] VERIFIKASI CREDENTIAL MATCH DENGAN CURL MANUAL WORKING:");
        $emailMd5 = md5($email);
        $passMd5 = md5($password);
        $expectedEmail = 'dummy@ortax.org';
        $expectedPass = 'Ortax123#';
        $emailMatchExpected = $emailMd5 === md5($expectedEmail);
        $passMatchExpected = $passMd5 === md5($expectedPass);
        $this->table(['Item', 'Value'], [
            ['APP_ENV', app()->environment()],
            ['ENV_FILE_LOADED_BY_LARAVEL', app()->environmentFile()],
            ['⚠️  FILE YANG ANDA EDIT vs YANG DILOAD', "Anda mengedit .env.production? → Laravel ACTUALLY loads file ini: " . app()->environmentFile()],
            ['CONFIG_CACHE_AKTIF? (laravel pakai cached config atau env?)', app()->configurationIsCached() ? 'YES — env() DIABAISKAN, nilai dari cache/config.php' : 'NO — env() dipakai'],
            ['Email AKTUAL yang dipakai', var_export($email, true) . ' (len=' . strlen($email) . ')'],
            ['Email MD5', $emailMd5],
            ['Email MATCH credential curl working (' . $expectedEmail . ')?', $emailMatchExpected ? '✅ SAMA PERSIS — SEHARUSNYA WORK' : '❌ TIDAK SAMA — PASTIKAN EMAIL BENAR!'],
            ['Password MASKED', str_repeat('*', max(4, strlen($password) - 4)) . substr($password, -4) . ' (len=' . strlen($password) . ')'],
            ['Password MD5', $passMd5],
            ['Password MATCH curl working (' . $expectedPass . ')?', $passMatchExpected ? '✅ SAMA PERSIS — SEHARUSNYA WORK' : '❌ TIDAK SAMA — PASTIKAN PASSWORD BENAR!'],
            ['MD5 REFERENSI email curl working', md5($expectedEmail)],
            ['MD5 REFERENSI password curl working', md5($expectedPass)],
        ]);

        if (!$emailMatchExpected || !$passMatchExpected) {
            $this->error("\n\n⚠️  ⚠️  ⚠️  CREDENTIAL TIDAK COCOK DENGAN CURL MANUAL YANG WORKING!");
            $this->line("   File yang DILOAD Laravel: <fg=yellow>" . app()->environmentFile() . "</>");
            $this->line("   Pastikan Anda edit FILE ITU (bukan .env.production kalau tidak diload!)");
            $this->line("   Solusi cepat: copy credential dari curl working ke file " . app()->environmentFile() . ",");
            $this->line("   lalu jalankan <fg=yellow>php artisan npwp:clear-all-caches</>");
            if (!$this->confirm('Lanjutkan saja dengan credential sekarang? (y/n)')) {
                return 1;
            }
        }

        $this->info("\n🚀 STEP 1: POST /auth/login (multipart/form-data)");
        $this->line("   POST {$baseUrl}/auth/login");

        try {
            $loginStart = microtime(true);
            $response = Http::acceptJson()
                ->asMultipart()
                ->timeout(30)
                ->post($baseUrl . '/auth/login', [
                    ['name' => 'email', 'contents' => $email],
                    ['name' => 'password', 'contents' => $password],
                ]);
            $loginDuration = round((microtime(true) - $loginStart) * 1000, 2);
        } catch (\Exception $e) {
            $this->error('   ❌ CONNECTION EXCEPTION: ' . $e->getMessage());
            return 1;
        }

        $httpStatus = $response->status();
        $rawBody = $response->body();
        $contentType = $response->header('Content-Type') ?? 'N/A';
        $bodyLength = strlen($rawBody);

        $this->line("");
        $this->table(['HTTP Status', 'Duration', 'Content-Type', 'Body Length'], [
            [$httpStatus, "{$loginDuration}ms", $contentType, "{$bodyLength} bytes"],
        ]);

        if (!$response->successful()) {
            $this->error("\n   ❌ LOGIN GAGAL (HTTP {$httpStatus}) - RAW BODY:");
            $this->line($this->indent($this->truncateSafe($rawBody, 6000), 3));
            return 2;
        }

        $this->info("\n   ✅ LOGIN HTTP 2xx OK - RAW BODY:");
        $this->line($this->indent($this->truncateSafe($rawBody, 4000), 3));

        $json = $response->json();
        $this->info("\n   🧱 JSON DECODED STRUCTURE:");
        if ($json === null) {
            $this->error('      ⚠ JSON TIDAK VALID! json_last_error: ' . json_last_error_msg());
        } else {
            $flat = [];
            $this->dumpFlat($json, '', $flat);
            foreach (array_slice($flat, 0, 80) as $line) {
                $this->line('      ' . $line);
            }
            if (count($flat) > 80) {
                $this->line('      ... (' . (count($flat) - 80) . ' keys more)');
            }
        }

        $token = null;
        $tokenExtractors = [
            'json.data.token' => fn() => $json['data']['token'] ?? null,
            'json.token' => fn() => $json['token'] ?? null,
            'json.access_token' => fn() => $json['access_token'] ?? null,
            'json.data.access_token' => fn() => $json['data']['access_token'] ?? null,
            'regex keyed "token":"eyJ..."' => function () use ($rawBody) {
                if (preg_match('/"(?:token|access_token|jwt)"\s*:\s*"(eyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,})"/', $rawBody, $m)) return $m[1];
                return null;
            },
            'regex bare eyJ...' => function () use ($rawBody) {
                if (preg_match('/(eyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,})/', $rawBody, $m)) return $m[1];
                return null;
            },
        ];

        foreach ($tokenExtractors as $name => $extractor) {
            $candidate = $extractor();
            if (is_string($candidate) && strlen($candidate) > 32) {
                $token = $candidate;
                $this->info("\n   🔑 TOKEN DITEMUKAN via [{$name}]:");
                $this->line('      ' . substr($token, 0, 50) . '... (total length: ' . strlen($token) . ')');
                break;
            }
        }

        if (!$token) {
            $this->error("\n   ❌ TIDAK ADA TOKEN YANG BISA DIEKSTRAK! Cek struktur response di atas.");
            return 3;
        }

        $this->info("\n🚀 STEP 2: POST /IF_CLB_059 (verify NPWP) - target NPWP: {$npwp}");
        $this->line("   POST {$baseUrl}/IF_CLB_059");
        $this->line("   Header: x-token: " . substr($token, 0, 20) . "...");
        $payload = ['npwp' => $npwp, 'tujuan' => 'Validasi NPWP'];
        $this->line("   Body JSON: " . json_encode($payload));

        try {
            $verifyStart = microtime(true);
            $verifyResponse = Http::asJson()
                ->acceptJson()
                ->timeout(30)
                ->withHeaders(['x-token' => $token])
                ->post($baseUrl . '/IF_CLB_059', $payload);
            $verifyDuration = round((microtime(true) - $verifyStart) * 1000, 2);
        } catch (\Exception $e) {
            $this->error('   ❌ VERIFY CONNECTION EXCEPTION: ' . $e->getMessage());
            return 4;
        }

        $this->line("");
        $this->table(['Verify HTTP Status', 'Duration', 'Content-Type', 'Body Length'], [
            [$verifyResponse->status(), "{$verifyDuration}ms", $verifyResponse->header('Content-Type') ?? 'N/A', strlen($verifyResponse->body()) . ' bytes'],
        ]);

        $verifyRaw = $verifyResponse->body();
        $this->info("\n   RAW RESPONSE VERIFY:");
        $this->line($this->indent($this->truncateSafe($verifyRaw, 8000), 3));

        $verifyJson = $verifyResponse->json();
        if (is_array($verifyJson)) {
            $this->info("\n   🧱 VERIFY JSON STRUCTURE FLATTENED:");
            $flat = [];
            $this->dumpFlat($verifyJson, '', $flat);
            foreach (array_slice($flat, 0, 100) as $line) {
                $this->line('      ' . $line);
            }
        }

        $this->warn("\n═══════════════════════════════════════════════════════════════");
        $this->line('   💡 Jika STEP 1 gagal extract token: kirimkan output RAW di atas');
        $this->line('      ke tim untuk ditambahkan pola extractor baru.');
        $this->warn('═══════════════════════════════════════════════════════════════');

        return 0;
    }

    private function indent(string $text, int $levels = 1): string
    {
        $pad = str_repeat('   ', $levels);
        return $pad . str_replace("\n", "\n" . $pad, $text);
    }

    private function truncateSafe(string $text, int $max): string
    {
        if (strlen($text) <= $max) return $text;
        return substr($text, 0, $max) . "\n... [TRUNCATED: total " . strlen($text) . " bytes, showing first {$max}]";
    }

    private function dumpFlat($data, string $prefix, array &$result): void
    {
        if (is_array($data) || is_object($data)) {
            foreach ((array)$data as $k => $v) {
                $key = ltrim($prefix . '.' . $k, '.');
                if (is_array($v) || is_object($v)) {
                    if (count((array)$v) > 20) {
                        $result[] = "<info>{$key}</info> = <comment>[" . gettype($v) . " with " . count((array)$v) . " items (too many)]</comment>";
                    } else {
                        $this->dumpFlat($v, $key, $result);
                    }
                } else {
                    $preview = is_string($v)
                        ? (strlen($v) > 120 ? substr($v, 0, 120) . '…<len='.strlen($v).'>' : $v)
                        : (is_bool($v) ? ($v ? 'TRUE' : 'FALSE') : (is_scalar($v) ? (string)$v : gettype($v)));
                    $result[] = "<info>{$key}</info> = <fg=yellow>{$preview}</>";
                }
            }
        }
    }
}
