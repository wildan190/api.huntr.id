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
                'whatsapp' => ['Invalid WhatsApp number format. Use format 08xxxxxxxxxx.'],
            ]);
        }

        $issued = OtpStore::issue($whatsapp);
        $otp = $issued['otp'];
        $otpToken = $issued['token'];

        $message = "Your Huntr.id OTP code is: {$otp}. Valid for 10 minutes. Do not share this code.";

        $delivery = $this->sendWhatsAppAction->execute($whatsapp, $message, false);

        $response = [
            'message' => ($delivery['ok'] ?? false)
                ? 'OTP successfully sent to your WhatsApp number.'
                : 'OTP created, but WhatsApp delivery failed.',
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
