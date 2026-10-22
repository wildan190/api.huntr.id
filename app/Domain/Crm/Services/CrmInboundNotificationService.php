<?php

namespace App\Domain\Crm\Services;

use App\Domain\Auth\Models\User;
use App\Domain\Communication\Notifications\DatabaseNotification;
use App\Domain\Company\Models\Company;
use App\Domain\Subscription\Models\CompanySubscription;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/** Delivers inbox events only to people entitled to use the tenant CRM. */
class CrmInboundNotificationService
{
    public function inboundMessage(string $companyId, string $channel, string $sender, string $body, string $conversationId): void
    {
        $channelLabel = $channel === 'whatsapp' ? 'WhatsApp' : 'email';
        $title = "Pesan {$channelLabel} baru";
        $preview = trim(preg_replace('/\s+/', ' ', $body) ?? '');
        $message = trim($sender . ($preview !== '' ? ': ' . Str::limit($preview, 120) : ' mengirim pesan baru.'));

        $this->recipients($companyId)->each(fn (User $user) => $user->notify(new DatabaseNotification(
            $title,
            $message,
            '/notifications',
            $channel === 'whatsapp' ? 'message-circle' : 'mail',
            [
                'type' => 'crm_inbound_message',
                'company_id' => $companyId,
                'conversation_id' => $conversationId,
                'channel' => $channel,
            ],
        )));
    }

    public function inboxSynced(string $companyId, string $provider, int $messageCount): void
    {
        if ($messageCount < 1) return;

        $this->recipients($companyId)->each(fn (User $user) => $user->notify(new DatabaseNotification(
            'Inbox CRM disinkronkan',
            "{$messageCount} email dari {$provider} siap ditinjau di Helpdesk.",
            '/notifications',
            'mail',
            [
                'type' => 'crm_email_sync',
                'company_id' => $companyId,
                'provider' => $provider,
            ],
        )));
    }

    /** @return Collection<int, User> */
    private function recipients(string $companyId): Collection
    {
        $company = Company::query()->find($companyId);
        if (! $company || ! $this->crmIsAvailable($company)) return collect();

        $members = $company->users()->get();
        $owner = User::query()->find($company->owner_id);
        if ($owner) $members->push($owner);

        return $members->unique('id')->values();
    }

    private function crmIsAvailable(Company $company): bool
    {
        if ($company->type !== 'buyer') return false;

        $subscription = CompanySubscription::query()
            ->where('company_id', $company->id)
            ->where('enterprise_plan', 'enterprise_pro')
            ->where('status', 'active')
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>', now())
            ->exists();

        return $subscription && \DB::table('company_apps')
            ->where('company_id', $company->id)
            ->where('app_key', 'crm')
            ->whereNotNull('installed_at')
            ->exists();
    }
}
