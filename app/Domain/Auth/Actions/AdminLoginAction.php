<?php

namespace App\Domain\Auth\Actions;

use App\Domain\Auth\Models\Admin;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AdminLoginAction
{
    /**
     * Authenticate admin credentials.
     *
     * @param array $credentials
     * @return array
     * @throws ValidationException
     */
    public function execute(array $credentials): array
    {
        $admin = Admin::where('email', $credentials['email'])->first();

        if (!$admin || !Hash::check($credentials['password'], $admin->password)) {
            throw ValidationException::withMessages([
                'email' => ['Invalid admin credentials.'],
            ]);
        }

        return [
            'message' => 'Admin login successful.',
            'admin'   => [
                'id'    => $admin->id,
                'name'  => $admin->name,
                'email' => $admin->email,
            ],
            'token'   => 'admin_session_token_' . $admin->id,
        ];
    }
}
