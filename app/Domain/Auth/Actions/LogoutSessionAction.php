<?php

namespace App\Domain\Auth\Actions;

use App\Domain\Auth\Models\User;
use Illuminate\Support\Facades\DB;

class LogoutSessionAction
{
    /**
     * Terminate a specific session.
     *
     * @param User $user
     * @param string $sessionId
     * @return bool
     */
    public function execute(User $user, string $sessionId): bool
    {
        // 1. Try deleting from database sessions
        $deleted = DB::table('sessions')
            ->where('user_id', $user->id)
            ->where('id', $sessionId)
            ->delete();

        // 2. If not found in sessions, try deleting from Sanctum tokens
        if (!$deleted && is_numeric($sessionId)) {
            $deleted = (bool) $user->tokens()->where('id', $sessionId)->delete();
        }

        return $deleted;
    }
}
