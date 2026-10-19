<?php

namespace App\Domain\Communication\Mail;

use App\Domain\Company\Models\Company;
use App\Domain\Rfq\Models\Rfq;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NewRelevantRequestMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Company $company,
        public readonly Rfq $rfq
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "[HUNTR Opportunity] Request Baru Sesuai Produk Anda: {$this->rfq->title}",
        );
    }

    public function content(): Content
    {
        $frontendUrl = rtrim(config('app.frontend_url', config('app.url', 'http://localhost:5173')), '/');

        return new Content(
            view: 'emails.new-relevant-request',
            with: [
                'company' => $this->company,
                'rfq' => $this->rfq,
                'rfqUrl' => "{$frontendUrl}/rfq/{$this->rfq->id}",
                'buyerName' => $this->rfq->company?->name ?? 'Buyer Perusahaan Terverifikasi',
                'items' => $this->rfq->items,
            ],
        );
    }
}
