<?php

namespace App\Domain\Auth\Actions;

use App\Domain\Auth\Repositories\UserRepositoryInterface;
use App\Support\WhatsappNumber;
use App\Support\OtpStore;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class ResetPasswordViaWhatsappAction
{
    public function __construct(
        private readonly UserRepositoryInterface $userRepository
    ) {
    }

    /**
     * Reset user password using verified WhatsApp.
     *
     * @param array $data
     * @return array
     * @throws ValidationException
     */
    public function execute(array $data): array
    {
        $whatsapp = WhatsappNumber::normalize($data['whatsapp'] ?? '');

        if ($whatsapp && !OtpStore::isVerified($whatsapp)) {
            throw ValidationException::withMessages([
                'whatsapp' => ['WhatsApp number has not been verified with OTP.'],
            ]);
        }

        $user = $this->userRepository->findByWhatsapp($whatsapp);

        if (!$user) {
            throw ValidationException::withMessages([
                'whatsapp' => ['No account found for this WhatsApp number.'],
            ]);
        }

        $user->password = Hash::make($data['password']);
        $user->save();

        OtpStore::consumeVerified($whatsapp);

        $user->tokens()->delete();

        Log::info('User password reset via WhatsApp', [
            'user_id' => $user->id,
            'whatsapp' => $whatsapp,
        ]);

        return [
            'message' => 'Password reset successfully. Please login with your new password.',
        ];
    }
}
