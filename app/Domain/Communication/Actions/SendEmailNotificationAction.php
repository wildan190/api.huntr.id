<?php

namespace App\Domain\Communication\Actions;

use App\Domain\Auth\Models\User;
use App\Domain\Company\Models\Company;
use App\Domain\Rfq\Models\Rfq;
use App\Domain\Communication\Mail\UserRegisteredMail;
use App\Domain\Communication\Mail\UserLoggedInMail;
use App\Domain\Communication\Mail\NewRelevantRequestMail;
use App\Domain\Communication\Mail\CompanyAuditMail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendEmailNotificationAction
{
    /**
     * Send registration welcome email to newly registered user.
     *
     * @param User $user
     * @param string $role
     * @return bool
     */
    public function sendRegistrationEmail(User $user, string $role = 'User'): bool
    {
        $email = trim((string) $user->email);
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Log::info("Skipping registration email: user {$user->id} has no valid email.");
            return false;
        }

        try {
            Mail::to($email)->send(new UserRegisteredMail($user, $role));
            Log::info("Registration welcome email dispatched to {$email} (user: {$user->id})");
            return true;
        } catch (\Throwable $e) {
            Log::error("Failed to send registration email to {$email}: " . $e->getMessage(), [
                'user_id' => $user->id,
                'trace' => $e->getTraceAsString(),
            ]);
            return false;
        }
    }

    /**
     * Send login security alert email to user.
     *
     * @param User $user
     * @param array $loginDetails
     * @return bool
     */
    public function sendLoginAlertEmail(User $user, array $loginDetails = []): bool
    {
        $email = trim((string) $user->email);
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        try {
            Mail::to($email)->send(new UserLoggedInMail($user, $loginDetails));
            Log::info("Login alert email dispatched to {$email} (user: {$user->id})");
            return true;
        } catch (\Throwable $e) {
            Log::error("Failed to send login alert email to {$email}: " . $e->getMessage(), [
                'user_id' => $user->id,
                'trace' => $e->getTraceAsString(),
            ]);
            return false;
        }
    }

    /**
     * Send new RFQ / request alert email to a relevant company.
     * Sends to the company's official email, owner email, and active company users.
     *
     * @param Company $company
     * @param Rfq $rfq
     * @return int Number of distinct recipients queued
     */
    public function sendNewRequestAlertToCompany(Company $company, Rfq $rfq): int
    {
        $company->loadMissing(['users', 'owner']);

        $recipients = collect();

        // 1. Company official email
        if (!empty($company->email)) {
            $recipients->push($company->email);
        }

        // 2. Company owner email
        if ($company->owner && !empty($company->owner->email)) {
            $recipients->push($company->owner->email);
        }

        // 3. Company member users
        if ($company->users) {
            foreach ($company->users as $member) {
                if (!empty($member->email)) {
                    $recipients->push($member->email);
                }
            }
        }

        $validEmails = $recipients
            ->map(fn($e) => trim((string) $e))
            ->filter(fn($e) => $e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL))
            ->unique()
            ->values();

        if ($validEmails->isEmpty()) {
            Log::info("No valid email addresses found for relevant company {$company->name} ({$company->id}) for RFQ {$rfq->id}");
            return 0;
        }

        $sentCount = 0;
        foreach ($validEmails as $email) {
            try {
                Mail::to($email)->send(new NewRelevantRequestMail($company, $rfq));
                $sentCount++;
            } catch (\Throwable $e) {
                Log::error("Failed to send new RFQ alert email to {$email} for company {$company->name}: " . $e->getMessage());
            }
        }

        Log::info("Dispatched {$sentCount} RFQ alert email(s) for company '{$company->name}' (RFQ: {$rfq->title})");
        return $sentCount;
    }

    /**
     * Send audit / document request notification email to company owner, official email, and users.
     *
     * @param Company $company
     * @param string $action 'request_docs', 'approve', or 'decline'
     * @param string|null $notes
     * @return int Number of distinct recipients queued
     */
    public function sendAuditNotificationToCompany(Company $company, string $action, ?string $notes = null): int
    {
        $company->loadMissing(['users', 'owner']);

        $recipients = collect();

        // 1. Company official email
        if (!empty($company->email)) {
            $recipients->push($company->email);
        }

        // 2. Company owner email
        if ($company->owner && !empty($company->owner->email)) {
            $recipients->push($company->owner->email);
        }

        // 3. Company member users
        if ($company->users) {
            foreach ($company->users as $member) {
                if (!empty($member->email)) {
                    $recipients->push($member->email);
                }
            }
        }

        $validEmails = $recipients
            ->map(fn($e) => trim((string) $e))
            ->filter(fn($e) => $e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL))
            ->unique()
            ->values();

        if ($validEmails->isEmpty()) {
            Log::info("No valid email addresses found for audit notification to company {$company->name} ({$company->id})");
            return 0;
        }

        $sentCount = 0;
        foreach ($validEmails as $email) {
            try {
                Mail::to($email)->send(new CompanyAuditMail($company, $action, $notes));
                $sentCount++;
            } catch (\Throwable $e) {
                Log::error("Failed to send audit notification email to {$email} for company {$company->name}: " . $e->getMessage());
            }
        }

        Log::info("Dispatched {$sentCount} audit notification email(s) for company '{$company->name}' (Action: {$action})");
        return $sentCount;
    }
}
