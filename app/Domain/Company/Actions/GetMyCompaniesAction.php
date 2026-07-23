<?php

namespace App\Domain\Company\Actions;

use App\Domain\Auth\Models\User;
use App\Domain\Company\Models\Company;

class GetMyCompaniesAction
{
    /**
     * Get all companies associated with a user.
     *
     * @param mixed $user
     * @return array
     */
    public function execute($user): array
    {
        if (!$user) {
            return [];
        }

        $userId = $user->id;

        $ownedCompanies = Company::with(['documents', 'catalogues'])
            ->where('owner_id', $userId)
            ->get();

        $memberCompanies = Company::with(['documents', 'catalogues'])
            ->whereHas('users', fn($q) => $q->where('id', $userId))
            ->whereNotIn('id', $ownedCompanies->pluck('id'))
            ->get();

        $allCompanies = $ownedCompanies->merge($memberCompanies);

        return $allCompanies->map(function ($company) {
            $data = $company->toArray();
            $data['formatted_tax_id'] = $company->formatted_tax_id;

            if ($company->type === 'buyer') {
                $data['stats'] = [
                    'total_pr' => \App\Domain\Rfq\Models\Rfq::where('company_id', $company->id)->count(),
                    'approved_pr' => \App\Domain\Rfq\Models\Rfq::where('company_id', $company->id)->whereIn('status', ['approved', 'active', 'awarded', 'closed'])->count(),
                ];
            } else {
                $data['stats'] = [
                    'total_proposals' => \App\Domain\Proposal\Models\Proposal::where('company_id', $company->id)->count(),
                    'won_proposals' => \App\Domain\Proposal\Models\Proposal::where('company_id', $company->id)->where('winner_status', 'approved')->count(),
                    'total_catalogues' => $company->catalogues->count(),
                ];
            }

            return $data;
        })->toArray();
    }
}
