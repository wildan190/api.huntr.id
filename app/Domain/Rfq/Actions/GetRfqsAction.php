<?php

namespace App\Domain\Rfq\Actions;

use App\Domain\Rfq\Models\Rfq;
use Illuminate\Database\Eloquent\Collection;

/**
 * Action to retrieve RFQs with filtering.
 */
class GetRfqsAction
{
    /**
     * Execute the action.
     *
     * @param array $params
     * @return Collection
     */
    public function execute(array $params): Collection
    {
        $companyId = $params['company_id'] ?? null;
        $userId = $params['user_id'] ?? null;
        $status = $params['status'] ?? null;
        
        $query = Rfq::with(['items.catalogue.company', 'company', 'user']);
        
        if ($companyId) {
            $query->where('company_id', $companyId);
        }

        if ($userId) {
            $query->where('user_id', $userId);
        }
        
        if ($status) {
            $query->where('status', $status);
        }
        
        return $query->latest()->get();
    }
}
