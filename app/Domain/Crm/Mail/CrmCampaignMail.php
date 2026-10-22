<?php
namespace App\Domain\Crm\Mail;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
class CrmCampaignMail extends Mailable { public function __construct(private string $subjectLine, private string $bodyText) {} public function envelope(): Envelope { return new Envelope(subject: $this->subjectLine); } public function content(): Content { return new Content(view: 'emails.crm-campaign', with: ['bodyText'=>$this->bodyText]); } }
