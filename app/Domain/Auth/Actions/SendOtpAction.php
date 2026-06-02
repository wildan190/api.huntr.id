<?php

namespace App\Domain\Auth\Actions;

use App\Domain\Communication\Actions\SendWhatsAppVerificationAction;
use App\Support\WhatsappNumber;
use App\Support\OtpStore;
use Illuminate\Validation\ValidationException;

class SendOtpAction
{
    protected $sendWhatsAppAction;

    public function __construct(SendWhatsAppVerificationAction $sendWhatsAppAction)
    {
        $this->sendWhatsAppAction = $sendWhatsAppAction;
    }

    /**
     * Issue and send an OTP code via WhatsApp.
     *
     * @param string $rawWhatsapp
     * @return array
     * @throws ValidationException
     */
    public function execute(string $rawWhatsapp): array
    {
        $whatsapp = WhatsappNumber::normalize($rawWhatsapp);

        if (! WhatsappNumber::isValid($whatsapp)) {
            throw ValidationException::withMessages([
                'whatsapp' => ['Format nomor WhatsApp tidak valid. Gunakan format 08xxxxxxxxxx.'],
            ]);
        }

        $issued = OtpStore::issue($whatsapp);
        $otp = $issued['otp'];
        $otpToken = $issued['token'];

        $message = "Kode OTP Huntr.id Anda adalah: {$otp}. Berlaku selama 10 menit. Jangan sebarkan kode ini.";

        $delivery = $this->sendWhatsAppAction->execute($whatsapp, $message, false);

        $response = [
            'message' => ($delivery['ok'] ?? false)
                ? 'OTP berhasil dikirim ke nomor WhatsApp Anda.'
                : 'OTP dibuat, tetapi pengiriman WhatsApp gagal.',
            'expires_in' => OtpStore::ttlSeconds(),
            'whatsapp' => $whatsapp,
            'otp_token' => $otpToken,
            'whatsapp_sent' => (bool) ($delivery['ok'] ?? false),
        ];

        if (! ($delivery['ok'] ?? false) && app()->environment('local')) {
            $response['delivery_error'] = $delivery['detail'] ?? 'unknown';
        }

        if (app()->environment('local') || config('app.debug')) {
            $response['otp'] = (string) $otp;
        }

        return $response;
    }
}
