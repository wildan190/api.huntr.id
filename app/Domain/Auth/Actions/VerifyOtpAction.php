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
            throw ValidationException::withMessages(['otp' => ['OTP code must be 6 digits.']]);
        }

        $whatsapp = null;

        if ($otpToken !== '') {
            $whatsapp = OtpStore::verifyByToken($otpToken, $otp);
            if ($whatsapp === null) {
                if (! OtpStore::hasPendingToken($otpToken)) {
                    throw ValidationException::withMessages(['otp' => ['OTP code has expired. Please request a new code.']]);
                }
                throw ValidationException::withMessages(['otp' => ['OTP code does not match. Check the code from WhatsApp again.']]);
            }
        } else {
            $whatsapp = WhatsappNumber::normalize($data['whatsapp'] ?? '');

            if (! WhatsappNumber::isValid($whatsapp)) {
                throw ValidationException::withMessages(['whatsapp' => ['Invalid WhatsApp number format.']]);
            }

            if (! OtpStore::hasPending($whatsapp)) {
                throw ValidationException::withMessages(['otp' => ['OTP code has expired. Please request a new code.']]);
            }

            if (! OtpStore::verify($whatsapp, $otp)) {
                throw ValidationException::withMessages(['otp' => ['OTP code does not match. Check the code from WhatsApp again.']]);
            }
        }

        OtpStore::markVerified($whatsapp);

        return [
            'message' => 'WhatsApp number successfully verified.',
            'verified' => true,
            'whatsapp' => $whatsapp,
        ];
    }
}
