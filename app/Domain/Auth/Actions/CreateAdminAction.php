<?php

namespace App\Domain\Auth\Actions;

use App\Domain\Auth\Models\Admin;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class CreateAdminAction
{
    /**
     * Create a new admin user.
     *
     * @param array $data
     * @return Admin
     * @throws ValidationException
     */
    public function execute(array $data): Admin
    {
        if (Admin::where('email', $data['email'])->exists()) {
            throw ValidationException::withMessages([
                'email' => ['Admin with this email already exists.'],
            ]);
        }

        return Admin::create([
            'name'     => $data['name'],
            'email'    => $data['email'],
            'password' => Hash::make($data['password']),
        ]);
    }
}
