<?php

namespace App\Domain\Admin\Actions;

use App\Domain\Auth\Models\User;

class DeleteUserAction
{
    /**
     * Delete a user only if they have no associated company.
     *
     * @throws \Exception if user has a company
     */
    public function execute(string $userId): void
    {
        $user = User::findOrFail($userId);

        if ($user->company_id !== null) {
            throw new \Exception("User ini memiliki perusahaan dan tidak bisa dihapus.");
        }

        // Also block if user owns any company
        if ($user->companies()->exists()) {
            throw new \Exception("User ini adalah owner perusahaan dan tidak bisa dihapus.");
        }

        // Revoke all Sanctum tokens
        $user->tokens()->delete();

        $user->delete();
    }
}
