<?php

namespace App\Domain\Crm\Services;

use App\Domain\Crm\Support\CrmContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Exceptions\HttpResponseException;
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
        $userId = $request->user()->id;
        $tenantId = $this->tenantKey($company->id, $userId);
        $this->upsertIntegration($company->id, $userId, 'initializing');
        $session = $this->callService(fn () => $this->serviceRequest()->post("/sessions/{$tenantId}")->throw()->json(), $company->id, $userId);
        for ($attempt = 0; $attempt < 8 && ($session['status'] ?? '') === 'initializing'; $attempt++) {
            usleep(500000);
            $session = $this->callService(fn () => $this->serviceRequest()->get("/sessions/{$tenantId}")->throw()->json(), $company->id, $userId);
        }
        $this->setIntegrationState($company->id, $userId, $session['status'] ?? 'initializing', $session);

        return response()->json(['session' => $session], 202);
    }

    public function status(Request $request)
    {
        $company = $this->context->tenant($request);
        $this->serviceSecret();
        $userId = $request->user()->id;
        $tenantId = $this->tenantKey($company->id, $userId);
        $response = $this->callService(fn () => $this->serviceRequest()->get("/sessions/{$tenantId}"), $company->id, $userId);
        if ($response->status() === 404) {
            $isConnectedBeforeRestart = DB::table('crm_integration_connections')
                ->where('company_id', $company->id)
                ->where('user_id', $userId)
                ->where('provider', 'whatsapp_web')
                ->where('channel', 'whatsapp')
                ->exists();

            if (! $isConnectedBeforeRestart) return response()->json(['session' => null]);

            // A RemoteAuth session lives in the encrypted CRM backup, not the container.
            // Re-create its in-memory WhatsApp client whenever the sidecar has restarted.
            $session = $this->callService(
                fn () => $this->serviceRequest()->post("/sessions/{$tenantId}")->throw()->json(),
                $company->id,
                $userId,
            );
            $this->setIntegrationState($company->id, $userId, $session['status'] ?? 'initializing', $session);

            return response()->json(['session' => $session]);
        }
        $session = $response->throw()->json();
        $this->setIntegrationState($company->id, $userId, $session['status'] ?? 'unknown', $session);

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
        $userId = $request->user()->id;
        $tenantId = $this->tenantKey($company->id, $userId);
        $this->ensureConnectedSession($company->id, $userId);
        $response = $this->callService(fn () => $this->serviceRequest()->post("/sessions/{$tenantId}/messages", $data), $company->id, $userId);
        if (! $response->successful()) {
            $this->throwForSendFailure($company->id, $userId, $response);
        }
        $payload = $response->json();
        if (! empty($data['conversation_id'])) {
            $conversation = DB::table('crm_channel_conversations')->where('company_id', $company->id)->where('user_id', $userId)->where('id', $data['conversation_id'])->first();
            if ($conversation) {
                DB::table('crm_conversation_messages')->insert([
                    'id' => (string) Str::uuid(), 'company_id' => $company->id, 'user_id' => $userId, 'conversation_id' => $conversation->id,
                    'author_id' => $request->user()->id, 'direction' => 'outbound', 'body' => $data['body'],
                    'metadata' => json_encode(['provider_message_id' => $payload['message_id'] ?? null]), 'delivered_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('crm_channel_conversations')->where('id', $conversation->id)->update(['last_message_at' => now(), 'updated_at' => now()]);
            }
        }

        return response()->json($payload, 201);
    }

    public function disconnect(Request $request)
    {
        $company = $this->context->tenant($request);
        $userId = $request->user()->id;
        $tenantId = $this->tenantKey($company->id, $userId);
        $response = $this->callService(fn () => $this->serviceRequest()->delete("/sessions/{$tenantId}"), $company->id, $userId);
        if (! in_array($response->status(), [204, 404], true)) {
            $response->throw();
        }
        DB::table('crm_integration_connections')
            ->where('company_id', $company->id)
            ->where('user_id', $userId)
            ->where('provider', 'whatsapp_web')
            ->where('channel', 'whatsapp')
            ->delete();
        DB::table('crm_whatsapp_session_backups')->where('company_id', $company->id)->where('user_id', $userId)->delete();

        return response()->json(['message' => 'Koneksi WhatsApp dan sesi perangkat telah dihapus.']);
    }

    /** Receives an encrypted-at-rest RemoteAuth archive from the private sidecar only. */
    public function storeSessionBackup(Request $request)
    {
        $this->authorizeService($request);
        $data = validator($request->all(), [
            'tenant_id' => ['required', 'string', 'max:80'],
            'archive' => ['required', 'string'],
        ])->validate();
        [$companyId, $userId] = $this->tenantIds($data['tenant_id']);
        $archive = base64_decode($data['archive'], true);
        abort_unless($archive !== false && strlen($archive) <= 16 * 1024 * 1024, 422, 'Arsip sesi WhatsApp tidak valid atau terlalu besar.');
        $existing = DB::table('crm_whatsapp_session_backups')->where('company_id', $companyId)->where('user_id', $userId)->first();
        DB::table('crm_whatsapp_session_backups')->updateOrInsert(
            ['company_id' => $companyId, 'user_id' => $userId],
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
        [$companyId, $userId] = $this->tenantIds($tenantId);
        $backup = DB::table('crm_whatsapp_session_backups')->where('company_id', $companyId)->where('user_id', $userId)->first();
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
            'tenant_id' => ['required', 'string', 'max:80'],
            'event' => ['required', 'in:qr,syncing,ready,auth_failed,disconnected,error,message'],
            'qr' => ['nullable', 'string'], 'phone' => ['nullable', 'string', 'max:40'], 'message' => ['nullable', 'string'],
            'external_id' => ['nullable', 'string'], 'customer_name' => ['nullable', 'string', 'max:255'],
            'media' => ['nullable', 'array'],
            'media.type' => ['nullable', 'in:image'],
            'media.mime_type' => ['nullable', 'in:image/jpeg,image/png,image/webp,image/gif'],
            'media.filename' => ['nullable', 'string', 'max:255'],
            'media.data' => ['nullable', 'string', 'max:7000000'],
            'body' => ['nullable', 'string'], 'message_id' => ['nullable', 'string'], 'received_at' => ['nullable', 'date'],
        ])->validate();

        [$data['company_id'], $data['user_id']] = $this->tenantIds($data['tenant_id']);
        if ($data['event'] === 'message') {
            $this->storeInboundMessage($data);
        } else {
            $status = match ($data['event']) {
                'qr' => 'awaiting_scan', 'syncing' => 'initializing', 'ready' => 'connected', default => $data['event'],
            };
            $this->setIntegrationState($data['company_id'], $data['user_id'], $status, $data);
        }

        return response()->json(['accepted' => true]);
    }

    private function storeInboundMessage(array $data): void
    {
        $companyId = $data['company_id'];
        $userId = $data['user_id'];
        $resolvedPhone = preg_replace('/\D/', '', (string) ($data['phone'] ?? ''));
        $phone = $resolvedPhone !== '' ? $resolvedPhone : preg_replace('/\D/', '', (string) $data['external_id']);
        $incomingName = trim((string) ($data['customer_name'] ?? ''));
        $fallbackName = $resolvedPhone !== '' ? 'WhatsApp +' . $resolvedPhone : 'Kontak WhatsApp';
        $customer = DB::table('crm_customers')->where('company_id', $companyId)->where('user_id', $userId)->where('phone', $phone)->first();
        if (! $customer) {
            $customerId = (string) Str::uuid();
            DB::table('crm_customers')->insert([
                'id' => $customerId, 'company_id' => $companyId, 'user_id' => $userId, 'name' => $incomingName !== '' ? $incomingName : $fallbackName,
                'phone' => $phone !== '' ? $phone : null, 'lifecycle_stage' => 'prospect', 'source' => 'whatsapp', 'language' => 'id', 'currency' => 'IDR',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $customer = DB::table('crm_customers')->find($customerId);
        } elseif ($incomingName !== '' && str_starts_with((string) $customer->name, 'WhatsApp ')) {
            DB::table('crm_customers')->where('id', $customer->id)->update(['name' => $incomingName, 'updated_at' => now()]);
            $customer = DB::table('crm_customers')->find($customer->id);
        }
        $conversation = DB::table('crm_channel_conversations')->where('company_id', $companyId)->where('user_id', $userId)->where('channel', 'whatsapp')->where('external_id', $data['external_id'])->first();
        if (! $conversation) {
            $conversationId = (string) Str::uuid();
            DB::table('crm_channel_conversations')->insert([
                'id' => $conversationId, 'company_id' => $companyId, 'user_id' => $userId, 'customer_id' => $customer->id,
                'channel' => 'whatsapp', 'external_id' => $data['external_id'], 'status' => 'open', 'last_message_at' => now(),
                'metadata' => json_encode(['provider' => 'whatsapp-web.js', 'phone_resolved' => $resolvedPhone !== '']), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $conversation = DB::table('crm_channel_conversations')->find($conversationId);
        } elseif ($conversation->customer_id !== $customer->id) {
            DB::table('crm_channel_conversations')->where('id', $conversation->id)->update(['customer_id' => $customer->id, 'updated_at' => now()]);
            $conversation = DB::table('crm_channel_conversations')->find($conversation->id);
        }
        if (! empty($data['message_id']) && DB::table('crm_conversation_messages')
            ->where('company_id', $companyId)->where('user_id', $userId)
            ->whereRaw("metadata::text like ?", ['%' . $data['message_id'] . '%'])
            ->exists()) return;
        $media = $this->storeInboundImage($companyId, $userId, $data['media'] ?? null);
        DB::table('crm_conversation_messages')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $companyId, 'user_id' => $userId, 'conversation_id' => $conversation->id,
            'direction' => 'inbound', 'body' => $data['body'] ?? '',
            'metadata' => json_encode(array_filter([
                'provider_message_id' => $data['message_id'] ?? null,
                'media' => $media,
            ])),
            'delivered_at' => $data['received_at'] ?? now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('crm_channel_conversations')->where('id', $conversation->id)->update(['last_message_at' => now(), 'updated_at' => now()]);
        $this->notifications->inboundMessage(
            $companyId,
            $userId,
            'whatsapp',
            $customer->name ?: $phone,
            $data['body'] ?? '',
            $conversation->id,
        );
    }

    /** Stores a bounded inbound image outside the message row and returns safe preview metadata. */
    private function storeInboundImage(string $companyId, string $userId, mixed $media): ?array
    {
        if (! is_array($media) || ($media['type'] ?? null) !== 'image') return null;

        $mimeType = (string) ($media['mime_type'] ?? '');
        $extension = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
        ][$mimeType] ?? null;
        $binary = base64_decode((string) ($media['data'] ?? ''), true);
        if (! $extension || $binary === false || $binary === '' || strlen($binary) > 5 * 1024 * 1024) return null;

        $path = "crm/whatsapp/{$companyId}/{$userId}/" . Str::uuid() . ".{$extension}";
        Storage::disk('public')->put($path, $binary);

        return [
            'type' => 'image',
            'url' => Storage::disk('public')->url($path),
            'mime_type' => $mimeType,
            'filename' => Str::limit((string) ($media['filename'] ?? 'foto-whatsapp'), 120, ''),
        ];
    }

    private function upsertIntegration(string $companyId, string $userId, string $status): void
    {
        $identity = ['company_id' => $companyId, 'user_id' => $userId, 'provider' => 'whatsapp_web', 'channel' => 'whatsapp'];
        $existing = DB::table('crm_integration_connections')->where($identity)->first();
        DB::table('crm_integration_connections')->updateOrInsert($identity, [
            ...$identity, 'id' => $existing?->id ?? (string) Str::uuid(), 'status' => $status,
            'settings' => json_encode([]), 'created_at' => $existing?->created_at ?? now(), 'updated_at' => now(),
        ]);
    }

    private function setIntegrationState(string $companyId, string $userId, string $status, array $settings): void
    {
        $this->upsertIntegration($companyId, $userId, $status);
        DB::table('crm_integration_connections')->where('company_id', $companyId)->where('user_id', $userId)->where('provider', 'whatsapp_web')->where('channel', 'whatsapp')->update([
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

    /** Restore a persisted RemoteAuth session after the WhatsApp sidecar restarts. */
    private function ensureConnectedSession(string $companyId, string $userId): void
    {
        $tenantId = $this->tenantKey($companyId, $userId);
        $response = $this->callService(fn () => $this->serviceRequest()->get("/sessions/{$tenantId}"), $companyId, $userId);
        $session = $response->status() === 404
            ? $this->callService(fn () => $this->serviceRequest()->post("/sessions/{$tenantId}")->throw()->json(), $companyId, $userId)
            : $response->throw()->json();

        if (in_array($session['status'] ?? null, ['error', 'auth_failed', 'disconnected'], true)) {
            $session = $this->callService(fn () => $this->serviceRequest()->post("/sessions/{$tenantId}")->throw()->json(), $companyId, $userId);
        }

        for ($attempt = 0; $attempt < 16; $attempt++) {
            if (($session['status'] ?? null) === 'connected') return;
            if (($session['status'] ?? null) === 'awaiting_scan') {
                $this->setIntegrationState($companyId, $userId, 'awaiting_scan', $session);
                throw ValidationException::withMessages([
                    'whatsapp' => 'Sesi WhatsApp memerlukan pemindaian QR kembali. Buka Automation lalu scan QR yang tersedia.',
                ]);
            }
            if (in_array($session['status'] ?? null, ['error', 'auth_failed', 'disconnected'], true)) {
                $this->setIntegrationState($companyId, $userId, $session['status'], $session);
                throw ValidationException::withMessages([
                    'whatsapp' => $session['message'] ?? $session['reason'] ?? 'Sesi WhatsApp tidak dapat dipulihkan. Hubungkan kembali dari Automation.',
                ]);
            }
            usleep(500000);
            $session = $this->callService(fn () => $this->serviceRequest()->get("/sessions/{$tenantId}")->throw()->json(), $companyId, $userId);
        }

        $this->setIntegrationState($companyId, $userId, $session['status'] ?? 'initializing', $session);
        throw ValidationException::withMessages([
            'whatsapp' => 'WhatsApp sedang memulihkan sesi. Tunggu beberapa detik lalu kirim ulang pesan.',
        ]);
    }

    /** The WhatsApp sidecar receives an opaque key so device sessions cannot cross CRM workspaces. */
    private function tenantKey(string $companyId, string $userId): string
    {
        return "{$companyId}_{$userId}";
    }

    /** @return array{0:string,1:string} */
    private function tenantIds(string $tenantId): array
    {
        $parts = explode('_', $tenantId, 2);
        abort_unless(count($parts) === 2 && Str::isUuid($parts[0]) && Str::isUuid($parts[1]), 404);
        abort_unless(DB::table('companies')->where('id', $parts[0])->exists(), 404);
        abort_unless(DB::table('users')->where('id', $parts[1])->exists(), 404);

        return $parts;
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

    /** Converts a sidecar send failure into a recoverable CRM response. */
    private function throwForSendFailure(string $companyId, string $userId, \Illuminate\Http\Client\Response $response): never
    {
        $payload = $response->json();
        $session = is_array($payload['session'] ?? null) ? $payload['session'] : [];
        $status = $session['status'] ?? ($response->status() === 409 ? 'disconnected' : 'error');

        if ($session) {
            $this->setIntegrationState($companyId, $userId, $status, $session);
        }

        if ($response->status() === 409 || in_array($status, ['awaiting_scan', 'auth_failed', 'disconnected'], true)) {
            throw new HttpResponseException(response()->json([
                'message' => 'Sesi WhatsApp tidak aktif. Buka Automation untuk scan QR atau hubungkan kembali.',
                'code' => 'whatsapp_session_unavailable',
                'action' => ['label' => 'Buka Automation', 'target' => 'automation'],
            ], 409));
        }

        throw new HttpResponseException(response()->json([
            'message' => $payload['message'] ?? 'WhatsApp tidak dapat mengirim pesan. Periksa nomor tujuan dan status sesi di Automation.',
            'code' => $payload['code'] ?? 'whatsapp_send_failed',
            'action' => ['label' => 'Buka Automation', 'target' => 'automation'],
        ], 503));
    }

    private function callService(callable $callback, ?string $companyId = null, ?string $userId = null): mixed
    {
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                return $callback();
            } catch (ConnectionException $exception) {
                if ($attempt < 3) {
                    usleep($attempt * 250000);
                    continue;
                }

                if ($companyId && $userId) {
                    $this->setIntegrationState($companyId, $userId, 'service_unavailable', [
                        'message' => 'Layanan WhatsApp CRM tidak merespons.',
                    ]);
                }

                throw new HttpResponseException(response()->json([
                    'message' => 'Layanan WhatsApp CRM sedang tidak tersedia. Coba lagi setelah layanan kembali aktif.',
                    'code' => 'crm_whatsapp_service_unavailable',
                    'action' => ['label' => 'Buka Automation', 'target' => 'automation'],
                ], 503));
            } catch (RequestException $exception) {
                report($exception);
                throw ValidationException::withMessages([
                    'whatsapp' => 'Sesi WhatsApp tidak dapat memproses pesan. Periksa status koneksi di Automation lalu hubungkan kembali bila diperlukan.',
                ]);
            }
        }

        throw new HttpResponseException(response()->json([
            'message' => 'Layanan WhatsApp CRM sedang tidak tersedia.',
            'code' => 'crm_whatsapp_service_unavailable',
        ], 503));
    }
}
