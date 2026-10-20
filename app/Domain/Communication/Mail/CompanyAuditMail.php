<?php

namespace App\Domain\Communication\Mail;

use App\Domain\Company\Models\Company;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class CompanyAuditMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Company $company,
        public readonly string $action,
        public readonly ?string $notes = null
    ) {}

    public function envelope(): Envelope
    {
        $subject = match ($this->action) {
            'request_docs' => "Permintaan Dokumen Legalitas untuk Perusahaan {$this->company->name} - HUNTR",
            'approve' => "Selamat! Perusahaan {$this->company->name} Telah Terverifikasi - HUNTR",
            'decline' => "Pemberitahuan Status Verifikasi Perusahaan {$this->company->name} - HUNTR",
            default => "Pembaruan Status Perusahaan {$this->company->name} - HUNTR",
        };

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        $frontendUrl = rtrim(config('app.frontend_url', config('app.url', 'http://localhost:5173')), '/');
        $companySlug = $this->company->slug ?: Str::slug($this->company->name);
        $actionUrl = match ($this->action) {
            'request_docs' => "{$frontendUrl}/{$companySlug}/company?tab=documents",
            default => "{$frontendUrl}/{$companySlug}/company",
        };

        return new Content(
            view: 'emails.company-audit',
            with: [
                'company' => $this->company,
                'action' => $this->action,
                'notes' => $this->notes,
                'actionUrl' => $actionUrl,
                'companyType' => ucfirst($this->company->type),
            ],
        );
    }
}
