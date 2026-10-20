<?php

namespace App\Domain\Company\Actions;

use App\Domain\Company\Repositories\CompanyRepositoryInterface;
use App\Domain\Company\Models\Company;
use App\Domain\Auth\Models\User;
use App\Domain\Communication\Actions\BroadcastWebsocketNotificationAction;
use App\Domain\Communication\Actions\SendWhatsAppNotificationAction;
use App\Domain\Communication\Actions\SendEmailNotificationAction;
use App\Domain\Communication\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AuditCompanyAction
{
    public function __construct(
        private readonly CompanyRepositoryInterface $companyRepository,
        private readonly BroadcastWebsocketNotificationAction $broadcastAction,
        private readonly SendWhatsAppNotificationAction $whatsAppAction,
        private readonly SendEmailNotificationAction $emailNotificationAction
    ) {}

    /**
     * Admin approve, decline, or request documents from a company.
     *
     * @param Company     $company The target company
     * @param string      $action  'approve', 'decline', or 'request_docs'
     * @param string|null $notes   Audit reason notes
     * @return Company
     */
    public function execute(Company $company, string $action, ?string $notes = null): Company
    {
        $update = ['verification_notes' => $notes];

        switch ($action) {
            case 'approve':
                $update['status']            = 'approved';
                $update['verification_tier'] = 'verified';
                break;

            case 'decline':
                $update['status']            = 'rejected';
                $update['verification_tier'] = $company->verification_tier ?? 'basic';
                break;

            case 'request_docs':
                // Admin explicitly asks the company to upload documents.
                // Promote starter → pending so it shows up in the pending queue.
                $update['status']            = 'pending';
                $update['verification_tier'] = 'standard';
                break;

            default:
                // Fallback: treat unknown action as decline
                $update['status']            = 'rejected';
                $update['verification_tier'] = $company->verification_tier ?? 'basic';
        }

        $updatedCompany = $this->companyRepository->update($company, $update);

        // Notify user(s) and company about the admin audit action
        $this->notifyRecipients($updatedCompany, $action, $notes);

        return $updatedCompany;
    }

    /**
     * Send in-app, realtime websocket, WhatsApp, and email notifications to the company & members.
     */
    private function notifyRecipients(Company $company, string $action, ?string $notes = null): void
    {
        $companySlug = $company->slug ?: Str::slug($company->name);
        $companyType = ucfirst($company->type);

        // Prepare title, body, and action URL based on action type
        if ($action === 'request_docs') {
            $title = "Permintaan Dokumen Legalitas";
            $body = $notes
                ? "Admin meminta dokumen legalitas perusahaan {$company->name}: \"{$notes}\". Silakan unggah NPWP, NIB, atau SIUP."
                : "Admin meminta Anda mengunggah dokumen legalitas untuk perusahaan {$company->name} (seperti NPWP, NIB, atau SIUP).";
            $url = "/{$companySlug}/company?tab=documents";
            $icon = 'shield-alert';
        } elseif ($action === 'approve') {
            $title = "Perusahaan Terverifikasi";
            $body = "Selamat! Profil perusahaan {$company->name} telah diverifikasi dan disetujui oleh tim Admin Huntr. Akses penuh aktif.";
            $url = "/{$companySlug}/company";
            $icon = 'check-circle';
        } else {
            $title = "Pembaruan Status Verifikasi";
            $body = $notes
                ? "Pengajuan verifikasi untuk perusahaan {$company->name} ditolak. Catatan: {$notes}"
                : "Pengajuan verifikasi untuk perusahaan {$company->name} ditolak oleh tim Admin.";
            $url = "/{$companySlug}/company";
            $icon = 'x-circle';
        }

        $notifData = [
            'type'         => $action === 'request_docs' ? 'document_request' : 'company_status_updated',
            'action'       => $action,
            'company_id'   => $company->id,
            'company_name' => $company->name,
            'company_type' => $company->type,
            'notes'        => $notes,
            'url'          => $url,
        ];

        // 1. Gather all users belonging to this company (owner, team members)
        $company->loadMissing('users');
        $recipients = collect();

        if ($company->owner_id) {
            $owner = User::find($company->owner_id);
            if ($owner) {
                $recipients->push($owner);
            }
        }

        if ($company->relationLoaded('users')) {
            $recipients = $recipients->merge($company->users);
        }

        $directMembers = User::where('company_id', $company->id)->get();
        if ($directMembers->isNotEmpty()) {
            $recipients = $recipients->merge($directMembers);
        }

        $uniqueUsers = $recipients->unique('id')->values();

        // 2. Dispatch Database Notification (In-App)
        $dbNotif = new DatabaseNotification($title, $body, $url, $icon, $notifData);
        try {
            $company->notify($dbNotif);
        } catch (\Throwable $e) {
            Log::warning("AuditCompanyAction: Failed to notify company model {$company->id}: " . $e->getMessage());
        }

        foreach ($uniqueUsers as $user) {
            try {
                $user->notify($dbNotif);
            } catch (\Throwable $e) {
                Log::warning("AuditCompanyAction: Failed to notify user {$user->id}: " . $e->getMessage());
            }
        }

        // 3. Dispatch Real-Time WebSocket Broadcast (Reverb)
        foreach ($uniqueUsers as $user) {
            try {
                $this->broadcastAction->execute(
                    $title,
                    $body,
                    'test-channel',
                    false, // synchronous broadcast to reverb
                    (string) $user->id,
                    $url,
                    $notifData
                );
            } catch (\Throwable $e) {
                Log::warning("AuditCompanyAction: Failed realtime broadcast to user {$user->id}: " . $e->getMessage());
            }
        }

        // 4. Send WhatsApp Notification
        try {
            if ($action === 'request_docs') {
                $waMessage = "📢 *Huntr.id - Permintaan Dokumen Legalitas*\n\n"
                    . "Halo, Tim Admin Huntr meminta Anda melengkapi dokumen legalitas untuk perusahaan *{$company->name}* ({$companyType}).\n\n"
                    . ($notes ? "📝 *Catatan Admin:*\n\"{$notes}\"\n\n" : "")
                    . "Silakan login ke platform Huntr dan unggah dokumen legalitas (seperti NPWP, NIB, atau SIUP) melalui menu Profil Perusahaan > Tab Legal Docs.\n\n"
                    . "Terima kasih,\nTim Huntr.id";
            } elseif ($action === 'approve') {
                $waMessage = "✅ *Huntr.id - Verifikasi Perusahaan Disetujui*\n\n"
                    . "Selamat! Profil perusahaan *{$company->name}* telah diverifikasi dan disetujui oleh tim Admin Huntr. Akses penuh ke platform telah aktif.\n\n"
                    . "Terima kasih,\nTim Huntr.id";
            } else {
                $waMessage = "⚠️ *Huntr.id - Status Verifikasi Perusahaan*\n\n"
                    . "Pengajuan verifikasi perusahaan *{$company->name}* belum dapat disetujui oleh tim Admin.\n"
                    . ($notes ? "\nCatatan: \"{$notes}\"\n" : "")
                    . "\nSilakan periksa kembali berkas dan kelengkapan profil Anda di platform Huntr.id.";
            }

            $this->whatsAppAction->toCompany($company, $waMessage, false);
        } catch (\Throwable $e) {
            Log::warning("AuditCompanyAction: Failed to send WhatsApp notification: " . $e->getMessage());
        }

        // 5. Send Email Notification
        try {
            $this->emailNotificationAction->sendAuditNotificationToCompany($company, $action, $notes);
        } catch (\Throwable $e) {
            Log::warning("AuditCompanyAction: Failed to send email notification: " . $e->getMessage());
        }
    }
}
