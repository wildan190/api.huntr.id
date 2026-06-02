<?php

namespace App\Domain\Auth\Actions;

use App\Domain\Auth\Models\User;

class CreateUserTokenAction
{
    /**
     * Create an API token for a user with optional expiration.
     *
     * @param User $user
     * @param string $deviceName
     * @param bool $rememberMe
     * @return string
     */
    public function execute(User $user, string $deviceName = 'Web Browser', bool $rememberMe = false): string
    {
        $expiresAt = $rememberMe 
            ? now()->addDays(30) 
            : now()->addDays(1);
        
        return $user->createToken(
            $deviceName,
            ['*'],
            $expiresAt
        )->plainTextToken;
    }
}
