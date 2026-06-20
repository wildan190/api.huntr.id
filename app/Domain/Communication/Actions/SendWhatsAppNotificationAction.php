<?php

namespace App\Domain\Communication\Actions;

use App\Domain\Auth\Models\User;
use App\Domain\Company\Models\Company;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class SendWhatsAppNotificationAction
{
    public function __construct(
        private readonly SendWhatsAppVerificationAction $sendWhatsAppAction
    ) {}

    /**
     * @return array<int, array{user_id: string, ok: bool, target?: string, detail?: string}>
     */
    public function toCompany(Company $company, string $message, bool $queued = false): array
    {
        $recipients = collect();

        $company->loadMissing('users');

        if ($company->owner_id) {
            $owner = User::find($company->owner_id);
            if ($owner) {
                $recipients->push($owner);
            }
        }

        if ($company->relationLoaded('users')) {
            $recipients = $recipients->merge($company->users);
        }

        return $this->toUsers($recipients->unique('id')->values(), $message, $queued);
    }

    /**
     * @param iterable<int, User> $users
     * @return array<int, array{user_id: string, ok: bool, target?: string, detail?: string}>
     */
    public function toUsers(iterable $users, string $message, bool $queued = false): array
    {
        $results = [];

        foreach ($users as $user) {
            if (! $user instanceof User) {
                continue;
            }

            $phone = $user->whatsapp ?? '';
            if ($phone === '') {
                continue;
            }

            $response = $this->sendWhatsAppAction->execute($phone, $message, $queued);
            $results[] = [
                'user_id' => (string) $user->id,
                'ok' => (bool) ($response['ok'] ?? false),
                'target' => $response['target'] ?? null,
                'detail' => $response['detail'] ?? null,
            ];

            if (! ($response['ok'] ?? false)) {
                Log::warning('SendWhatsAppNotificationAction failed', [
                    'user_id' => $user->id,
                    'detail' => $response['detail'] ?? null,
                ]);
            }
        }

        return $results;
    }
}
