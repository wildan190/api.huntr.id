<?php

namespace App\Domain\Auth\Actions;

use App\Support\WhatsappNumber;
use App\Support\OtpStore;
use Illuminate\Validation\ValidationException;

class VerifyOtpAction
{
    /**
     * Verify an OTP code.
     *
     * @param array $data
     * @return array
     * @throws ValidationException
     */
    public function execute(array $data): array
    {
        $otp = preg_replace('/\D/', '', trim($data['otp']));
        $otpToken = trim((string) ($data['otp_token'] ?? ''));

        if (strlen($otp) !== 6) {
            throw ValidationException::withMessages(['otp' => ['Kode OTP harus 6 digit.']]);
        }

        $whatsapp = null;

        if ($otpToken !== '') {
            $whatsapp = OtpStore::verifyByToken($otpToken, $otp);
            if ($whatsapp === null) {
                if (! OtpStore::hasPendingToken($otpToken)) {
                    throw ValidationException::withMessages(['otp' => ['Kode OTP telah kedaluwarsa. Silakan minta kode baru.']]);
                }
                throw ValidationException::withMessages(['otp' => ['Kode OTP tidak sesuai. Periksa kembali kode dari WhatsApp.']]);
            }
        } else {
            $whatsapp = WhatsappNumber::normalize($data['whatsapp'] ?? '');

            if (! WhatsappNumber::isValid($whatsapp)) {
                throw ValidationException::withMessages(['whatsapp' => ['Format nomor WhatsApp tidak valid.']]);
            }

            if (! OtpStore::hasPending($whatsapp)) {
                throw ValidationException::withMessages(['otp' => ['Kode OTP telah kedaluwarsa. Silakan minta kode baru.']]);
            }

            if (! OtpStore::verify($whatsapp, $otp)) {
                throw ValidationException::withMessages(['otp' => ['Kode OTP tidak sesuai. Periksa kembali kode dari WhatsApp.']]);
            }
        }

        OtpStore::markVerified($whatsapp);

        return [
            'message' => 'Nomor WhatsApp berhasil diverifikasi.',
            'verified' => true,
            'whatsapp' => $whatsapp,
        ];
    }
}
