<?php

namespace App\Actions\Fortify;

use App\Domain\Auth\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use App\Support\WhatsappNumber;
use App\Support\OtpStore;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function create(array $input): User
    {
        if (isset($input['whatsapp'])) {
            $input['whatsapp'] = WhatsappNumber::normalize($input['whatsapp']);
        }

        $whatsapp = $input['whatsapp'] ?? null;

        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'whatsapp' => ['required', 'string', Rule::unique(User::class)],
            'email' => [
                'nullable',
                'string',
                'email',
                'max:255',
                Rule::unique(User::class),
            ],
            'password' => $this->passwordRules(),
        ])->validate();

        // Enforce OTP verification before allowing registration
        if ($whatsapp && ! OtpStore::isVerified($whatsapp)) {
            throw ValidationException::withMessages([
                'whatsapp' => ['Nomor WhatsApp belum terverifikasi dengan OTP.'],
            ]);
        }

        $user = User::create([
            'name' => $input['name'],
            'email' => $input['email'] ?? null,
            'whatsapp' => $whatsapp,
            'password' => Hash::make($input['password']),
            'role' => 'buyer', // Default role
        ]);

        // Consume the verification token
        if ($whatsapp) {
            OtpStore::consumeVerified($whatsapp);
        }

        return $user;
    }
}
