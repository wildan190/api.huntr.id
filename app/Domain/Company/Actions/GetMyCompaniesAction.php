<?php

namespace App\Domain\Company\Actions;

use App\Domain\Auth\Models\User;
use App\Domain\Company\Models\Company;

class GetMyCompaniesAction
{
    /**
     * Get all companies associated with a user.
     *
     * @param int $userId
     * @return array
     */
    public function execute(int $userId): array
    {
        $user = User::find($userId);
        if (!$user) {
            return [];
        }

        // 1. Get companies owned by the user
        $ownedCompanies = Company::with('documents')
            ->where('owner_id', $userId)
            ->get();

        // 2. Get companies where the user is a member (via company_id)
        $memberCompanies = Company::with('documents')
            ->whereHas('users', fn($q) => $q->where('id', $userId))
            ->whereNotIn('id', $ownedCompanies->pluck('id'))
            ->get();

        return $ownedCompanies->merge($memberCompanies)->toArray();
    }
}
