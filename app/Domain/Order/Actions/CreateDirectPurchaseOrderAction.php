<?php

namespace App\Domain\Order\Actions;

use App\Domain\Auth\Models\User;
use App\Domain\Budget\Services\BudgetCommitmentService;
use App\Domain\Company\Services\CompanyAccessService;
use App\Domain\Order\Models\PurchaseOrder;
use App\Domain\Rfq\Models\Rfq;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\UnauthorizedException;

class CreateDirectPurchaseOrderAction
{
    public function __construct(
        private readonly BudgetCommitmentService $budgetCommitments,
        private readonly CompanyAccessService $access,
    ) {}

    public function execute(User $actor, Rfq $rfq, array $data): PurchaseOrder
    {
        if ((string) $rfq->company_id !== (string) $data['company_id']) {
            abort(422, 'The PR does not belong to this buyer workspace.');
        }

        $rfq->loadMissing('company');
        $this->access->assertCan($actor, $rfq->company, 'procurement.winner.select');

        return DB::transaction(function () use ($rfq, $data, $actor) {
            $lockedRfq = Rfq::query()->lockForUpdate()->findOrFail($rfq->id);

            abort_unless($lockedRfq->procurement_mode === 'direct', 422, 'Only direct purchase PRs can create a custom PO.');
            abort_unless($lockedRfq->status === 'approved', 422, 'Approve this PR before creating a PO.');
            abort_if(PurchaseOrder::where('rfq_id', $lockedRfq->id)->exists(), 422, 'A PO has already been created from this PR.');

            $items = $lockedRfq->items;
            abort_if($items->isEmpty(), 422, 'A direct PO requires at least one approved PR item.');

            $total = $items->sum(fn ($item) => (float) ($item->estimated_price ?? 0) * (float) $item->qty);
            $reference = strtoupper(substr(str_replace('-', '', $lockedRfq->id), -6));

            $purchaseOrder = PurchaseOrder::create([
                'buyer_company_id' => $lockedRfq->company_id,
                'rfq_id' => $lockedRfq->id,
                'procurement_plan_id' => $lockedRfq->procurement_plan_id,
                'procurement_type' => $lockedRfq->procurement_type ?? 'goods',
                'project_type' => $lockedRfq->project_type ?? null,
                'vendor_id' => null,
                'vendor_name' => $data['vendor_name'],
                'po_number' => 'PO-'.now()->format('Ymd').'-'.$reference,
                'status' => 'issued',
                'created_by' => $actor->id,
                'approved_by' => $lockedRfq->approved_by,
                'total_amount' => $total,
                'currency' => strtoupper($data['currency'] ?? 'IDR'),
                'purchase_category' => $data['purchase_category'] ?? 'Direct purchase',
                'purchase_type' => $data['purchase_type'] ?? 'Immediate',
                'order_date' => today(),
                'expected_receiving_date' => $data['expected_receiving_date'] ?? null,
                'service_start_date' => $data['service_start_date'] ?? $lockedRfq->service_start_date,
                'service_end_date' => $data['service_end_date'] ?? $lockedRfq->service_end_date,
                'service_location' => $data['service_location'] ?? $lockedRfq->service_location,
                'acceptance_criteria' => $data['acceptance_criteria'] ?? $lockedRfq->acceptance_criteria,
                'requires_service_acceptance' => in_array($lockedRfq->procurement_type ?? 'goods', ['services', 'mixed'], true),
                'delivery_point' => $lockedRfq->delivery_point,
                'department' => $lockedRfq->department ?? 'General Procurement',
            ]);

            $this->budgetCommitments->sync($purchaseOrder);

            return $purchaseOrder->fresh();
        });
    }
}
