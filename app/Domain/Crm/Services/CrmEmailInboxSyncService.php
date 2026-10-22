<?php

namespace App\Domain\Crm\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** Imports inbound provider mail into tenant conversations; credentials never leave the server. */
class CrmEmailInboxSyncService
{
    public function syncCompany(string $companyId): int
    {
        $connections = DB::table('crm_integration_connections')->where('company_id', $companyId)
            ->where('channel', 'email')->whereIn('provider', ['gmail', 'microsoft'])->where('status', 'connected')->get();
        return $connections->sum(fn ($connection) => $this->syncConnection($connection));
    }

    public function syncRequest(Request $request, \App\Domain\Crm\Support\CrmContext $context)
    {
        $company = $context->tenant($request);
        return response()->json(['imported' => $this->syncCompany($company->id)]);
    }

    private function syncConnection(object $connection): int
    {
        $tokens = json_decode(Crypt::decryptString($connection->encrypted_credentials), true, 512, JSON_THROW_ON_ERROR);
        $messages = $connection->provider === 'gmail' ? $this->gmailMessages($tokens) : $this->microsoftMessages($tokens);
        $imported = 0;
        foreach ($messages as $message) {
            $imported += $this->storeMessage($connection->company_id, $connection->provider, $message) ? 1 : 0;
        }
        DB::table('crm_integration_connections')->where('id', $connection->id)->update(['last_synced_at' => now(), 'updated_at' => now()]);
        return $imported;
    }

    private function gmailMessages(array $tokens): array
    {
        $ids = Http::withToken($tokens['access_token'])->get('https://gmail.googleapis.com/gmail/v1/users/me/messages', ['maxResults' => 25, 'q' => 'newer_than:14d'])->throw()->json('messages') ?? [];
        return collect($ids)->map(function (array $item) use ($tokens) {
            $message = Http::withToken($tokens['access_token'])->get("https://gmail.googleapis.com/gmail/v1/users/me/messages/{$item['id']}", ['format' => 'full'])->throw()->json();
            $headers = collect($message['payload']['headers'] ?? [])->mapWithKeys(fn ($header) => [strtolower($header['name']) => $header['value']]);
            return ['id' => $message['id'], 'from' => $headers['from'] ?? '', 'subject' => $headers['subject'] ?? '(Tanpa subjek)', 'body' => $this->gmailBody($message['payload'] ?? []), 'received_at' => now()];
        })->all();
    }

    private function gmailBody(array $payload): string
    {
        if (($payload['mimeType'] ?? '') === 'text/plain' && ! empty($payload['body']['data'])) return base64_decode(strtr($payload['body']['data'], '-_', '+/')) ?: '';
        foreach ($payload['parts'] ?? [] as $part) { $body = $this->gmailBody($part); if ($body !== '') return $body; }
        return '';
    }

    private function microsoftMessages(array $tokens): array
    {
        $items = Http::withToken($tokens['access_token'])->get('https://graph.microsoft.com/v1.0/me/messages?$top=25&$select=id,subject,from,body,receivedDateTime')->throw()->json('value') ?? [];
        return collect($items)->map(fn (array $item) => ['id' => $item['id'], 'from' => $item['from']['emailAddress']['address'] ?? '', 'subject' => $item['subject'] ?? '(Tanpa subjek)', 'body' => trim(strip_tags($item['body']['content'] ?? '')), 'received_at' => $item['receivedDateTime'] ?? now()])->all();
    }

    private function storeMessage(string $companyId, string $provider, array $message): bool
    {
        $externalMessageId = "{$provider}:{$message['id']}";
        if (DB::table('crm_conversation_messages')->where('company_id', $companyId)->whereRaw('metadata::text like ?', ['%' . $externalMessageId . '%'])->exists()) return false;
        preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $message['from'], $matches);
        $email = strtolower($matches[0] ?? '');
        $customer = $email ? DB::table('crm_customers')->where('company_id', $companyId)->whereRaw('lower(email) = ?', [$email])->first() : null;
        if (! $customer) { $customerId = (string) Str::uuid(); DB::table('crm_customers')->insert(['id'=>$customerId,'company_id'=>$companyId,'name'=>$email ?: $message['from'],'email'=>$email ?: null,'lifecycle_stage'=>'prospect','source'=>'email','language'=>'id','currency'=>'IDR','created_at'=>now(),'updated_at'=>now()]); $customer=DB::table('crm_customers')->find($customerId); }
        $externalConversationId = "{$provider}:{$email}";
        $conversation = DB::table('crm_channel_conversations')->where('company_id', $companyId)->where('external_id', $externalConversationId)->first();
        if (! $conversation) { $id=(string)Str::uuid(); DB::table('crm_channel_conversations')->insert(['id'=>$id,'company_id'=>$companyId,'customer_id'=>$customer->id,'channel'=>'email','external_id'=>$externalConversationId,'status'=>'open','last_message_at'=>now(),'metadata'=>json_encode(['provider'=>$provider,'subject'=>$message['subject']]),'created_at'=>now(),'updated_at'=>now()]); $conversation=DB::table('crm_channel_conversations')->find($id); }
        DB::table('crm_conversation_messages')->insert(['id'=>(string)Str::uuid(),'company_id'=>$companyId,'conversation_id'=>$conversation->id,'direction'=>'inbound','body'=>trim($message['subject']."\n\n".$message['body']),'metadata'=>json_encode(['provider_message_id'=>$externalMessageId]),'delivered_at'=>$message['received_at'],'created_at'=>now(),'updated_at'=>now()]);
        DB::table('crm_channel_conversations')->where('id',$conversation->id)->update(['last_message_at'=>now(),'updated_at'=>now()]);
        return true;
    }
}
