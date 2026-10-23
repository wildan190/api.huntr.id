<?php

namespace App\Domain\Crm\Services;

use App\Domain\Auth\Models\User;
use App\Domain\Communication\Notifications\DatabaseNotification;
use App\Domain\Company\Models\Company;
use App\Domain\Subscription\Models\CompanySubscription;
use Illuminate\Support\Str;

/** Delivers private CRM inbox events only to the owner of that personal workspace. */
class CrmInboundNotificationService
{
    public function inboundMessage(string $companyId, string $userId, string $channel, string $sender, string $body, string $conversationId): void
    {
        $user = $this->recipient($companyId, $userId);
        if (! $user) return;

        $channelLabel = $channel === 'whatsapp' ? 'WhatsApp' : 'email';
        $preview = trim(preg_replace('/\s+/', ' ', $body) ?? '');
        $message = trim($sender . ($preview !== '' ? ': ' . Str::limit($preview, 120) : ' mengirim pesan baru.'));

        $user->notify(new DatabaseNotification(
            "Pesan {$channelLabel} baru",
            $message,
            '/notifications',
            $channel === 'whatsapp' ? 'message-circle' : 'mail',
            [
                'type' => 'crm_inbound_message',
                'company_id' => $companyId,
                'conversation_id' => $conversationId,
                'channel' => $channel,
            ],
        ));
    }

    public function inboxSynced(string $companyId, string $userId, string $provider, int $messageCount): void
    {
        if ($messageCount < 1 || ! ($user = $this->recipient($companyId, $userId))) return;

        $user->notify(new DatabaseNotification(
            'Inbox CRM disinkronkan',
            "{$messageCount} email dari {$provider} siap ditinjau di Helpdesk.",
            '/notifications',
            'mail',
            [
                'type' => 'crm_email_sync',
                'company_id' => $companyId,
                'provider' => $provider,
            ],
        ));
    }

    private function recipient(string $companyId, string $userId): ?User
    {
        $company = Company::query()->find($companyId);
        if (! $company || ! $this->crmIsAvailable($company)) return null;

        $user = User::query()->find($userId);
        if (! $user) return null;

        $isOwner = $company->owner_id === $user->id;
        $isMember = $company->users()->whereKey($user->id)->exists();

        return $isOwner || $isMember ? $user : null;
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
