<?php

namespace App\Domain\Communication\Mail;

use App\Domain\Auth\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class UserRegisteredMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly string $role = 'User'
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Selamat Datang di HUNTR - Akun Anda Berhasil Terdaftar',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.welcome-registered',
            with: [
                'user' => $this->user,
                'role' => $this->role,
                'loginUrl' => rtrim(config('app.frontend_url', config('app.url', 'http://localhost:5173')), '/') . '/login',
            ],
        );
    }
}
