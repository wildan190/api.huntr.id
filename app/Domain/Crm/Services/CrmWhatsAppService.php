<?php

namespace App\Domain\Crm\Services;

use App\Domain\Crm\Support\CrmContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Validation\ValidationException;

/** Bridges tenant CRM data to the private whatsapp-web.js container. */
class CrmWhatsAppService
{
    public function __construct(private CrmContext $context) {}

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

        return response()->json(['message' => 'Koneksi WhatsApp dan sesi perangkat telah dihapus.']);
    }

    public function webhook(Request $request)
    {
        $secret = (string) config('services.crm.whatsapp.webhook_secret');
        $provided = (string) $request->header('x-crm-service-secret');
        abort_unless($secret !== '' && hash_equals($secret, $provided), 401);
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

    private function serviceSecret(): string
    {
        $secret = (string) config('services.crm.whatsapp.webhook_secret');
        if ($secret === '') throw ValidationException::withMessages(['whatsapp' => 'CRM_WHATSAPP_WEBHOOK_SECRET belum dikonfigurasi.']);
        return $secret;
    }

    private function callService(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (ConnectionException $exception) {
            throw ValidationException::withMessages([
                'whatsapp' => 'Layanan WhatsApp belum aktif. Jalankan `docker compose up -d --build whatsapp`, lalu coba lagi.',
            ]);
        } catch (RequestException $exception) {
            report($exception);
            throw ValidationException::withMessages([
                'whatsapp' => 'Layanan WhatsApp gagal membuat sesi. Periksa `docker compose logs --tail=100 whatsapp` untuk detailnya.',
            ]);
        }
    }
}
