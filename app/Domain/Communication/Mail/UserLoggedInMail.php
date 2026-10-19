<?php

namespace App\Domain\Communication\Mail;

use App\Domain\Auth\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class UserLoggedInMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly array $loginDetails = []
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '[Keamanan Akun HUNTR] Aktivitas Login Baru Terdeteksi',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.login-alert',
            with: [
                'user' => $this->user,
                'deviceName' => $this->loginDetails['device_name'] ?? 'Web Browser',
                'ipAddress' => $this->loginDetails['ip_address'] ?? 'N/A',
                'loginTime' => $this->loginDetails['login_time'] ?? now()->timezone('Asia/Jakarta')->format('d M Y, H:i') . ' WIB',
                'dashboardUrl' => rtrim(config('app.frontend_url', config('app.url', 'http://localhost:5173')), '/'),
            ],
        );
    }
}
