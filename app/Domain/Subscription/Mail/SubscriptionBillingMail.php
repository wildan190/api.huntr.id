<?php

namespace App\Domain\Subscription\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class SubscriptionBillingMail extends Mailable
{
    public function __construct(public readonly array $bill) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: ($this->bill['paid'] ? 'Pembayaran diterima' : 'Tagihan subscription').' Huntr - '.$this->bill['order_id']);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.subscription-billing', with: ['bill' => $this->bill]);
    }
}
