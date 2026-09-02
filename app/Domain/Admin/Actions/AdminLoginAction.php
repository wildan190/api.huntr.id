<?php

namespace App\Domain\Admin\Actions;

use App\Domain\Admin\Models\Admin;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
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

        $token = Str::random(64);
        Cache::put('admin_session:' . hash('sha256', $token), $admin->id, now()->addHours(8));

        return [
            'message' => 'Admin login successful.',
            'admin'   => [
                'id'    => $admin->id,
                'name'  => $admin->name,
                'email' => $admin->email,
            ],
            'token'   => $token,
        ];
    }
}
