<?php

namespace App\Domain\Company\Actions;

use App\Domain\Auth\Models\User;
use App\Domain\Company\Models\ApprovalRequest;
use App\Domain\Company\Models\Company;
use App\Domain\Company\Services\CompanyAccessService;
use App\Domain\Order\Actions\ConfirmPurchaseOrderAction;
use App\Domain\Order\Models\PurchaseOrder;
use App\Domain\Proposal\Actions\ApproveWinnerAction;
use App\Domain\Proposal\Models\Proposal;
use App\Domain\Rfq\Actions\ApproveRfqAction;
use App\Domain\Rfq\Models\Rfq;

/** Applies an approval decision and completes the domain transition on its final stage. */
class ApproveWorkflowRequestAction
{
    public function __construct(private CompanyAccessService $access) {}

    public function execute(Company $company, ApprovalRequest $request, User $actor, ?string $notes = null): array
    {
        $result = $this->access->approveSubject(
            $company,
            $request->subject_type,
            $request->subject_id,
            $actor,
            (float) $request->amount,
            $request->requested_by,
            $request->metadata ?? [],
            $notes,
        );
        if (! $result['completed']) return $result;

        $entity = match ($request->subject_type) {
            'purchase_request' => $this->approvePurchaseRequest($request, $actor),
            'winner_approval' => $this->approveWinner($request, $actor),
            'po_confirmation' => $this->confirmPurchaseOrder($company, $request, $actor),
            default => null,
        };

        return [...$result, 'entity' => $entity];
    }

    private function approvePurchaseRequest(ApprovalRequest $request, User $actor): ?Rfq
    {
        $rfq = Rfq::query()->with('company')->find($request->subject_id);
        return $rfq ? app(ApproveRfqAction::class)->execute($actor, $rfq) : null;
    }

    private function approveWinner(ApprovalRequest $request, User $actor): ?Proposal
    {
        $proposal = Proposal::query()->with('rfq.company')->find($request->subject_id);
        return $proposal ? app(ApproveWinnerAction::class)->execute($proposal, $actor->id) : null;
    }

    private function confirmPurchaseOrder(Company $company, ApprovalRequest $request, User $actor): ?PurchaseOrder
    {
        $po = PurchaseOrder::query()->find($request->subject_id);
        return $po ? app(ConfirmPurchaseOrderAction::class)->execute($company, $po, $actor) : null;
    }
}
