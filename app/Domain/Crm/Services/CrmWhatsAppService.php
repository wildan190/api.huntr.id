<?php

namespace App\Domain\Crm\Services;

use App\Domain\Crm\Support\CrmContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Validation\ValidationException;

/** Bridges tenant CRM data to the private whatsapp-web.js container. */
class CrmWhatsAppService
{
    public function __construct(
        private CrmContext $context,
        private CrmInboundNotificationService $notifications,
    ) {}

    public function start(Request $request)
    {
        $company = $this->context->tenant($request);
        $this->serviceSecret();
        $this->upsertIntegration($company->id, 'initializing');
        $session = $this->callService(fn () => $this->serviceRequest()->post("/sessions/{$company->id}")->throw()->json());
        for ($attempt = 0; $attempt < 8 && ($session['status'] ?? '') === 'initializing'; $attempt++) {
            usleep(500000);
            $session = $this->callService(fn () => $this->serviceRequest()->get("/sessions/{$company->id}")->throw()->json());
        }
        $this->setIntegrationState($company->id, $session['status'] ?? 'initializing', $session);

        return response()->json(['session' => $session], 202);
    }

    public function status(Request $request)
    {
        $company = $this->context->tenant($request);
        $this->serviceSecret();
        $response = $this->callService(fn () => $this->serviceRequest()->get("/sessions/{$company->id}"));
        if ($response->status() === 404) return response()->json(['session' => null]);
        $session = $response->throw()->json();
        $this->setIntegrationState($company->id, $session['status'] ?? 'unknown', $session);

        return response()->json(['session' => $session]);
    }

    public function send(Request $request)
    {
        $company = $this->context->tenant($request);
        $data = validator($request->all(), [
            'to' => ['required', 'string', 'max:40'],
            'body' => ['required', 'string', 'max:4096'],
            'conversation_id' => ['nullable', 'uuid'],
        ])->validate();
        $this->ensureConnectedSession($company->id);
        $response = $this->callService(fn () => $this->serviceRequest()->post("/sessions/{$company->id}/messages", $data)->throw()->json());
        if (! empty($data['conversation_id'])) {
            $conversation = DB::table('crm_channel_conversations')->where('company_id', $company->id)->where('id', $data['conversation_id'])->first();
            if ($conversation) {
                DB::table('crm_conversation_messages')->insert([
                    'id' => (string) Str::uuid(), 'company_id' => $company->id, 'conversation_id' => $conversation->id,
                    'author_id' => $request->user()->id, 'direction' => 'outbound', 'body' => $data['body'],
                    'metadata' => json_encode(['provider_message_id' => $response['message_id'] ?? null]), 'delivered_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('crm_channel_conversations')->where('id', $conversation->id)->update(['last_message_at' => now(), 'updated_at' => now()]);
            }
        }

        return response()->json($response, 201);
    }

    public function disconnect(Request $request)
    {
        $company = $this->context->tenant($request);
        $response = $this->callService(fn () => $this->serviceRequest()->delete("/sessions/{$company->id}"));
        if (! in_array($response->status(), [204, 404], true)) {
            $response->throw();
        }
        DB::table('crm_integration_connections')
            ->where('company_id', $company->id)
            ->where('provider', 'whatsapp_web')
            ->where('channel', 'whatsapp')
            ->delete();
        DB::table('crm_whatsapp_session_backups')->where('company_id', $company->id)->delete();

        return response()->json(['message' => 'Koneksi WhatsApp dan sesi perangkat telah dihapus.']);
    }

    /** Receives an encrypted-at-rest LocalAuth archive from the private sidecar only. */
    public function storeSessionBackup(Request $request)
    {
        $this->authorizeService($request);
        $data = validator($request->all(), [
            'tenant_id' => ['required', 'uuid'],
            'archive' => ['required', 'string'],
        ])->validate();
        $archive = base64_decode($data['archive'], true);
        abort_unless($archive !== false && strlen($archive) <= 16 * 1024 * 1024, 422, 'Arsip sesi WhatsApp tidak valid atau terlalu besar.');
        abort_unless(DB::table('companies')->where('id', $data['tenant_id'])->exists(), 404);
        $existing = DB::table('crm_whatsapp_session_backups')->where('company_id', $data['tenant_id'])->first();
        DB::table('crm_whatsapp_session_backups')->updateOrInsert(
            ['company_id' => $data['tenant_id']],
            [
                'id' => $existing?->id ?? (string) Str::uuid(),
                'encrypted_archive' => Crypt::encryptString($data['archive']),
                'archive_size' => strlen($archive),
                'last_backed_up_at' => now(),
                'created_at' => $existing?->created_at ?? now(),
                'updated_at' => now(),
            ],
        );

        return response()->json(['stored' => true], 201);
    }

    /** Returns the archive only to the private WhatsApp sidecar authenticated by its shared secret. */
    public function sessionBackup(Request $request, string $tenantId)
    {
        $this->authorizeService($request);
        abort_unless(validator(['tenant_id' => $tenantId], ['tenant_id' => ['uuid']])->passes(), 404);
        $backup = DB::table('crm_whatsapp_session_backups')->where('company_id', $tenantId)->first();
        if (! $backup) return response()->json(['archive' => null], 404);

        return response()->json([
            'archive' => Crypt::decryptString($backup->encrypted_archive),
            'last_backed_up_at' => $backup->last_backed_up_at,
        ]);
    }

    public function webhook(Request $request)
    {
        $this->authorizeService($request);
        $data = validator($request->all(), [
            'tenant_id' => ['required', 'uuid'],
            'event' => ['required', 'in:qr,ready,auth_failed,disconnected,error,message'],
            'qr' => ['nullable', 'string'], 'phone' => ['nullable', 'string'], 'message' => ['nullable', 'string'],
            'external_id' => ['nullable', 'string'], 'body' => ['nullable', 'string'], 'message_id' => ['nullable', 'string'], 'received_at' => ['nullable', 'date'],
        ])->validate();

        if ($data['event'] === 'message') {
            $this->storeInboundMessage($data);
        } else {
            $status = match ($data['event']) {
                'qr' => 'awaiting_scan', 'ready' => 'connected', default => $data['event'],
            };
            $this->setIntegrationState($data['tenant_id'], $status, $data);
        }

        return response()->json(['accepted' => true]);
    }

    private function storeInboundMessage(array $data): void
    {
        $companyId = $data['tenant_id'];
        $phone = preg_replace('/\D/', '', (string) $data['external_id']);
        $customer = DB::table('crm_customers')->where('company_id', $companyId)->where('phone', $phone)->first();
        if (! $customer) {
            $customerId = (string) Str::uuid();
            DB::table('crm_customers')->insert([
                'id' => $customerId, 'company_id' => $companyId, 'name' => 'WhatsApp ' . $phone,
                'phone' => $phone, 'lifecycle_stage' => 'prospect', 'source' => 'whatsapp', 'language' => 'id', 'currency' => 'IDR',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $customer = DB::table('crm_customers')->find($customerId);
        }
        $conversation = DB::table('crm_channel_conversations')->where('company_id', $companyId)->where('channel', 'whatsapp')->where('external_id', $data['external_id'])->first();
        if (! $conversation) {
            $conversationId = (string) Str::uuid();
            DB::table('crm_channel_conversations')->insert([
                'id' => $conversationId, 'company_id' => $companyId, 'customer_id' => $customer->id,
                'channel' => 'whatsapp', 'external_id' => $data['external_id'], 'status' => 'open', 'last_message_at' => now(),
                'metadata' => json_encode(['provider' => 'whatsapp-web.js']), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $conversation = DB::table('crm_channel_conversations')->find($conversationId);
        }
        if (! empty($data['message_id']) && DB::table('crm_conversation_messages')
            ->where('company_id', $companyId)
            ->whereRaw("metadata::text like ?", ['%' . $data['message_id'] . '%'])
            ->exists()) return;
        DB::table('crm_conversation_messages')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $companyId, 'conversation_id' => $conversation->id,
            'direction' => 'inbound', 'body' => $data['body'] ?? '', 'metadata' => json_encode(['provider_message_id' => $data['message_id'] ?? null]),
            'delivered_at' => $data['received_at'] ?? now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('crm_channel_conversations')->where('id', $conversation->id)->update(['last_message_at' => now(), 'updated_at' => now()]);
        $this->notifications->inboundMessage(
            $companyId,
            'whatsapp',
            $customer->name ?: $phone,
            $data['body'] ?? '',
            $conversation->id,
        );
    }

    private function upsertIntegration(string $companyId, string $status): void
    {
        $identity = ['company_id' => $companyId, 'provider' => 'whatsapp_web', 'channel' => 'whatsapp'];
        $existing = DB::table('crm_integration_connections')->where($identity)->first();
        DB::table('crm_integration_connections')->updateOrInsert($identity, [
            ...$identity, 'id' => $existing?->id ?? (string) Str::uuid(), 'status' => $status,
            'settings' => json_encode([]), 'created_at' => $existing?->created_at ?? now(), 'updated_at' => now(),
        ]);
    }

    private function setIntegrationState(string $companyId, string $status, array $settings): void
    {
        $this->upsertIntegration($companyId, $status);
        DB::table('crm_integration_connections')->where('company_id', $companyId)->where('provider', 'whatsapp_web')->where('channel', 'whatsapp')->update([
            'status' => $status, 'settings' => json_encode(array_filter($settings, fn ($key) => in_array($key, ['qr', 'phone', 'message', 'reason', 'delivery_error', 'updated_at'], true), ARRAY_FILTER_USE_KEY)),
            'last_synced_at' => $status === 'connected' ? now() : null, 'updated_at' => now(),
        ]);
    }

    private function serviceRequest()
    {
        return Http::baseUrl(rtrim((string) config('services.crm.whatsapp.service_url'), '/'))
            ->withHeaders(['x-crm-service-secret' => $this->serviceSecret()])
            ->acceptJson()->timeout(15);
    }

    /** Restore a persisted LocalAuth session after the WhatsApp sidecar restarts. */
    private function ensureConnectedSession(string $companyId): void
    {
        $response = $this->callService(fn () => $this->serviceRequest()->get("/sessions/{$companyId}"));
        $session = $response->status() === 404
            ? $this->callService(fn () => $this->serviceRequest()->post("/sessions/{$companyId}")->throw()->json())
            : $response->throw()->json();

        if (in_array($session['status'] ?? null, ['error', 'auth_failed', 'disconnected'], true)) {
            $session = $this->callService(fn () => $this->serviceRequest()->post("/sessions/{$companyId}")->throw()->json());
        }

        for ($attempt = 0; $attempt < 16; $attempt++) {
            if (($session['status'] ?? null) === 'connected') return;
            if (($session['status'] ?? null) === 'awaiting_scan') {
                $this->setIntegrationState($companyId, 'awaiting_scan', $session);
                throw ValidationException::withMessages([
                    'whatsapp' => 'Sesi WhatsApp memerlukan pemindaian QR kembali. Buka Automation lalu scan QR yang tersedia.',
                ]);
            }
            if (in_array($session['status'] ?? null, ['error', 'auth_failed', 'disconnected'], true)) {
                $this->setIntegrationState($companyId, $session['status'], $session);
                throw ValidationException::withMessages([
                    'whatsapp' => $session['message'] ?? $session['reason'] ?? 'Sesi WhatsApp tidak dapat dipulihkan. Hubungkan kembali dari Automation.',
                ]);
            }
            usleep(500000);
            $session = $this->callService(fn () => $this->serviceRequest()->get("/sessions/{$companyId}")->throw()->json());
        }

        $this->setIntegrationState($companyId, $session['status'] ?? 'initializing', $session);
        throw ValidationException::withMessages([
            'whatsapp' => 'WhatsApp sedang memulihkan sesi. Tunggu beberapa detik lalu kirim ulang pesan.',
        ]);
    }

    private function serviceSecret(): string
    {
        $secret = (string) config('services.crm.whatsapp.webhook_secret');
        if ($secret === '') throw ValidationException::withMessages(['whatsapp' => 'CRM_WHATSAPP_WEBHOOK_SECRET belum dikonfigurasi.']);
        return $secret;
    }

    private function authorizeService(Request $request): void
    {
        $secret = $this->serviceSecret();
        abort_unless(hash_equals($secret, (string) $request->header('x-crm-service-secret')), 401);
    }

    private function callService(callable $callback): mixed
    {
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                return $callback();
            } catch (ConnectionException $exception) {
                if ($attempt < 3) {
                    usleep($attempt * 250000);
                    continue;
                }
                throw ValidationException::withMessages([
                    'whatsapp' => 'Koneksi WhatsApp sedang tidak tersedia. Buka Automation untuk menyambungkan ulang setelah layanan kembali aktif.',
                ]);
            } catch (RequestException $exception) {
                report($exception);
                throw ValidationException::withMessages([
                    'whatsapp' => 'Sesi WhatsApp tidak dapat memproses pesan. Periksa status koneksi di Automation lalu hubungkan kembali bila diperlukan.',
                ]);
            }
        }

        throw ValidationException::withMessages(['whatsapp' => 'Koneksi WhatsApp tidak tersedia.']);
    }
}
