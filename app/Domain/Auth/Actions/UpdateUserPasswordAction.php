<?php

namespace App\Domain\Auth\Actions;

use App\Domain\Auth\Models\User;
use Illuminate\Support\Facades\Hash;

class UpdateUserPasswordAction
{
    /**
     * Update the user's password.
     *
     * @param User $user
     * @param string $password
     * @return void
     */
    public function execute(User $user, string $password): void
    {
        $user->update([
            'password' => Hash::make($password),
        ]);
    }
}
