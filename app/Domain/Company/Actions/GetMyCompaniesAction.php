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

        // User has company_id set — return that company along with all members
        $companies = [];
        if ($user->company_id) {
            $company = Company::with('documents')->find($user->company_id);
            if ($company) {
                $companies = [$company];
            }
        }

        // Also return any companies that have this user as a member
        $memberCompanies = Company::with('documents')
            ->whereHas('users', fn($q) => $q->where('id', $userId))
            ->whereNotIn('id', array_column($companies, 'id'))
            ->get();

        return array_merge($companies, $memberCompanies->toArray());
    }
}
