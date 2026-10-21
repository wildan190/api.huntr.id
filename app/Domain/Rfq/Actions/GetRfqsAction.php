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
        
        $query = Rfq::with(['items.catalogue.company', 'company', 'user', 'proposals.company']);
        
        // Data Isolation: If status is not a published global RFQ status, company_id or user_id MUST be provided
        // to prevent companies from seeing each other's private data (drafts, pending_approval, rejected).
        $publishedStatuses = ['active', 'closed', 'awarded', 'all_published', 'all_global'];
        if (!in_array($status, $publishedStatuses) && !$companyId && !$userId) {
            return new Collection();
        }

        if ($companyId) {
            $query->where('company_id', $companyId);
        }

        if ($userId) {
            $query->where('user_id', $userId);
        }
        
        if (in_array($status, ['all_published', 'all_global'])) {
            $query->whereIn('status', ['active', 'awarded', 'closed']);
        } elseif ($status) {
            $query->where('status', $status);
        }
        
        return $query->latest()->get();
    }
}
