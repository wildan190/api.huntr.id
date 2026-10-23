<?php

namespace App\Domain\Crm\Services;

use App\Domain\Crm\Support\CrmContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Handles OAuth authorization for provider mailboxes without exposing tokens to the client. */
class CrmOAuthService
{
    private const PROVIDERS = ['gmail', 'microsoft'];

    public function __construct(private CrmContext $context) {}

    public function authorize(Request $request, string $provider)
    {
        $company = $this->context->tenant($request);
        $config = $this->providerConfig($provider);
        $state = Str::random(64);
        Cache::put($this->stateKey($state), [
            'company_id' => $company->id,
            'user_id' => $request->user()->id,
            'provider' => $provider,
        ], now()->addMinutes(10));

        return response()->json([
            'authorization_url' => $config['authorize_url'] . '?' . http_build_query([
                'client_id' => $config['client_id'],
                'redirect_uri' => $this->redirectUri($provider),
                'response_type' => 'code',
                'scope' => $config['scope'],
                'state' => $state,
                'access_type' => 'offline',
                'prompt' => 'consent',
            ]),
        ]);
    }

    public function callback(Request $request, string $provider)
    {
        $state = (string) $request->query('state');
        $session = $state ? Cache::pull($this->stateKey($state)) : null;
        if (! $session || $session['provider'] !== $provider) {
            return $this->callbackPage('Otorisasi CRM tidak valid atau sudah berakhir.', false);
        }
        if ($request->query('error')) {
            return $this->callbackPage('Otorisasi dibatalkan oleh penyedia email.', false);
        }

        try {
            $config = $this->providerConfig($provider);
            $token = Http::asForm()->post($config['token_url'], [
                'client_id' => $config['client_id'],
                'client_secret' => $config['client_secret'],
                'code' => $request->query('code'),
                'redirect_uri' => $this->redirectUri($provider),
                'grant_type' => 'authorization_code',
            ])->throw()->json();
            $email = $this->profileEmail($provider, $token['access_token'] ?? '');
            if (! $email) throw ValidationException::withMessages(['provider' => 'Provider tidak mengembalikan alamat email.']);

            DB::transaction(function () use ($session, $provider, $token, $email) {
                $integrationIdentity = ['company_id' => $session['company_id'], 'user_id' => $session['user_id'], 'provider' => $provider, 'channel' => 'email'];
                $integrationId = (string) (DB::table('crm_integration_connections')->where($integrationIdentity)->value('id') ?? Str::uuid());
                DB::table('crm_integration_connections')->updateOrInsert($integrationIdentity, [
                    ...$integrationIdentity,
                    'id' => $integrationId,
                    'status' => 'connected',
                    'encrypted_credentials' => Crypt::encryptString(json_encode($token, JSON_THROW_ON_ERROR)),
                    'settings' => json_encode(['email' => $email]),
                    'last_synced_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('crm_email_connections')->updateOrInsert(
                    ['company_id' => $session['company_id'], 'user_id' => $session['user_id'], 'email' => $email],
                    [
                        'id' => (string) (DB::table('crm_email_connections')->where('company_id', $session['company_id'])->where('user_id', $session['user_id'])->where('email', $email)->value('id') ?? Str::uuid()),
                        'user_id' => $session['user_id'],
                        'provider' => $provider,
                        'status' => 'connected',
                        'settings' => json_encode(['integration_connection_id' => $integrationId]),
                        'last_synced_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );
            });
        } catch (\Throwable $exception) {
            report($exception);
            return $this->callbackPage('Koneksi email gagal diselesaikan. Periksa konfigurasi OAuth dan coba lagi.', false);
        }

        return $this->callbackPage('Email berhasil terhubung. Anda dapat menutup jendela ini.', true);
    }

    private function providerConfig(string $provider): array
    {
        if (! in_array($provider, self::PROVIDERS, true)) {
            throw ValidationException::withMessages(['provider' => 'Provider OAuth tidak didukung.']);
        }
        $config = config("services.crm.{$provider}");
        if (empty($config['client_id']) || empty($config['client_secret'])) {
            throw ValidationException::withMessages(['provider' => "OAuth {$provider} belum dikonfigurasi pada environment."]);
        }

        return $config;
    }

    private function profileEmail(string $provider, string $accessToken): ?string
    {
        if ($provider === 'gmail') {
            return Http::withToken($accessToken)->get('https://www.googleapis.com/oauth2/v2/userinfo')->throw()->json('email');
        }

        $profile = Http::withToken($accessToken)
            ->get('https://graph.microsoft.com/v1.0/me?$select=mail,userPrincipalName')
            ->throw()
            ->json();

        return $profile['mail'] ?? $profile['userPrincipalName'] ?? null;
    }

    private function redirectUri(string $provider): string
    {
        return config("services.crm.{$provider}.redirect_uri") ?: url("/api/crm/oauth/{$provider}/callback");
    }

    private function stateKey(string $state): string
    {
        return "crm-oauth:{$state}";
    }

    private function callbackPage(string $message, bool $success)
    {
        $encoded = json_encode(['type' => 'crm-oauth', 'success' => $success], JSON_THROW_ON_ERROR);
        return response("<!doctype html><title>CRM Email</title><script>window.opener?.postMessage({$encoded}, '*');</script><p>{$message}</p>", 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
