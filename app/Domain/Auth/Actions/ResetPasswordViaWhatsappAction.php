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
    ) {}

    /**
     * Reset user password using verified WhatsApp.
     *
     * @param array $data Input fields: whatsapp, password
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

        // Find user by whatsapp
        $user = $this->userRepository->model()::where('whatsapp', $whatsapp)->first();

        if (!$user) {
            // Even if verified, the number isn't associated with an account
            throw ValidationException::withMessages([
                'whatsapp' => ['No account found for this WhatsApp number.'],
            ]);
        }

        // Update password
        $user->password = Hash::make($data['password']);
        $user->save();

        // Consume the OTP token so it can't be reused for registration or another reset
        OtpStore::consumeVerified($whatsapp);

        // Delete all current tokens so user has to log in again on all devices
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
