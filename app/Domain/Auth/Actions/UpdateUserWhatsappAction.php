<?php

namespace App\Domain\Auth\Actions;

use App\Domain\Auth\Models\User;
use App\Support\WhatsappNumber;
use App\Support\OtpStore;
use Illuminate\Validation\ValidationException;

class UpdateUserWhatsappAction
{
    /**
     * Update the user's WhatsApp number.
     *
     * @param User $user
     * @param string $whatsappInput
     * @return User
     * @throws ValidationException
     */
    public function execute(User $user, string $whatsappInput): User
    {
        $whatsapp = WhatsappNumber::normalize($whatsappInput);

        if ($whatsapp === '') {
            throw ValidationException::withMessages([
                'whatsapp' => ['Invalid WhatsApp number.'],
            ]);
        }

        // Enforce OTP verification before allowing update
        if (! OtpStore::isVerified($whatsapp)) {
            throw ValidationException::withMessages([
                'whatsapp' => ['New WhatsApp number has not been verified with OTP.'],
            ]);
        }

        $user->update([
            'whatsapp' => $whatsapp,
        ]);

        OtpStore::consumeVerified($whatsapp);

        return $user;
    }
}
