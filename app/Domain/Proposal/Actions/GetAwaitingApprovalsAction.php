<?php

namespace App\Domain\Proposal\Actions;

use App\Domain\Proposal\Models\Proposal;

class GetAwaitingApprovalsAction
{
    /**
     * Get all proposals awaiting manager approval (winner_status = 'awarded').
     *
     * @param string|null $companyId Filter by buyer company ID
     * @return array
     */
    public function execute(?string $companyId = null): array
    {
        $query = Proposal::where('winner_status', 'awarded')
            ->with(['rfq', 'company'])
            ->orderBy('awarded_at', 'desc');

        if ($companyId) {
            $query->whereHas('rfq', function($q) use ($companyId) {
                $q->where('company_id', $companyId);
            });
        } else {
            // Data Isolation: Must provide company_id to see awaiting approvals
            return [];
        }

        $proposals = $query->get();

        return $proposals->map(function (Proposal $proposal) {
            return [
                'id' => $proposal->id,
                'rfq_id' => $proposal->rfq_id,
                'rfq_title' => $proposal->rfq->title,
                'buyer_name' => $proposal->rfq->buyer_name,
                'buyer_company_id' => $proposal->rfq->buyer_company_id,
                'company_id' => $proposal->company_id,
                'company_name' => $proposal->company->name,
                'price_offer' => $proposal->price_offer,
                'delivery_days' => $proposal->delivery_days,
                'warranty_months' => $proposal->warranty_months,
                'payment_term' => $proposal->payment_term,
                'document_path' => $proposal->document_path,
                'awarded_at' => $proposal->awarded_at,
                'awarded_by_user_name' => $proposal->awarded_by_user_id ? 'Buyer' : null,
                'total_participants' => Proposal::where('rfq_id', $proposal->rfq_id)->count(),
            ];
        })->toArray();
    }
}
