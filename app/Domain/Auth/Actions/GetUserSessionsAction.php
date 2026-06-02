<?php

namespace App\Domain\Auth\Actions;

use App\Domain\Auth\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

class GetUserSessionsAction
{
    /**
     * Get the user's active sessions (Web and API).
     *
     * @param User $user
     * @param string|null $currentSessionId
     * @return Collection
     */
    public function execute(User $user, ?string $currentSessionId = null): Collection
    {
        // 1. Get database sessions (if any)
        $dbSessions = DB::table('sessions')
            ->where('user_id', $user->id)
            ->orderBy('last_activity', 'desc')
            ->get()
            ->map(function ($session) use ($currentSessionId) {
                return [
                    'id' => $session->id,
                    'type' => 'Web Session',
                    'ip_address' => $session->ip_address,
                    'user_agent' => $session->user_agent,
                    'is_current_device' => $session->id === $currentSessionId,
                    'last_active' => date('Y-m-d H:i:s', $session->last_activity),
                ];
            });

        // 2. Get personal access tokens (Sanctum)
        $tokens = $user->tokens()
            ->orderBy('last_used_at', 'desc')
            ->get()
            ->map(function ($token) {
                return [
                    'id' => $token->id,
                    'type' => 'API Token',
                    'name' => $token->name,
                    'ip_address' => 'N/A',
                    'user_agent' => 'Bearer Token',
                    'is_current_device' => false,
                    'last_active' => $token->last_used_at ? $token->last_used_at->toDateTimeString() : 'Never used',
                ];
            });

        return $dbSessions->concat($tokens);
    }
}
