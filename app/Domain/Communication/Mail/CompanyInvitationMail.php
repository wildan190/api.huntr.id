<?php

namespace App\Domain\Communication\Mail;

use App\Domain\Auth\Models\User;
use App\Domain\Company\Models\Company;
use App\Domain\Company\Models\CompanyInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CompanyInvitationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Company $company,
        public readonly User $inviter,
        public readonly CompanyInvitation $invitation
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Undangan Bergabung ke Tim {$this->company->name} di HUNTR",
        );
    }

    public function content(): Content
    {
        $frontendUrl = rtrim(config('app.frontend_url', config('app.url', 'http://localhost:5173')), '/');
        $inviteUrl = "{$frontendUrl}/invite/accept?token={$this->invitation->token}";

        return new Content(
            view: 'emails.company-invitation',
            with: [
                'company' => $this->company,
                'inviter' => $this->inviter,
                'invitation' => $this->invitation,
                'inviteUrl' => $inviteUrl,
                'roleName' => ucfirst($this->invitation->role),
                'expiresAt' => $this->invitation->expires_at ? $this->invitation->expires_at->timezone('Asia/Jakarta')->format('d M Y, H:i') . ' WIB' : '7 Hari',
            ],
        );
    }
}
