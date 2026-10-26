<?php

namespace App\Domain\Budget\Services;

use App\Domain\Order\Models\PurchaseOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Records the financial commitment made by a PO against its procurement plan. */
class BudgetCommitmentService
{
    public function sync(
        PurchaseOrder $purchaseOrder,
        ?float $amount = null,
        ?string $requestedPlanId = null,
        ?string $note = null,
        ?string $occurredAt = null,
        ?string $createdBy = null,
    ): void
    {
        $purchaseOrder->loadMissing('rfq');
        $planId = $requestedPlanId ?: $purchaseOrder->getAttribute('procurement_plan_id')
            ?: $purchaseOrder->rfq?->getAttribute('procurement_plan_id');

        if (! $planId) {
            return;
        }

        $plan = DB::table('procurement_plans')
            ->where('id', $planId)
            ->where('company_id', $purchaseOrder->buyer_company_id)
            ->first();

        if (! $plan || ($plan->purchase_order_id && (string) $plan->purchase_order_id !== (string) $purchaseOrder->id)) {
            return;
        }

        $committedAmount = max(0, $amount ?? (float) $purchaseOrder->total_amount);
        $this->ensureWithinAllocation($plan, $committedAmount);

        DB::table('purchase_orders')->where('id', $purchaseOrder->id)->update([
            'procurement_plan_id' => $plan->id,
            'updated_at' => now(),
        ]);

        DB::table('procurement_plans')->where('id', $plan->id)->update([
            'purchase_order_id' => $purchaseOrder->id,
            'status' => $plan->status === 'completed' ? 'completed' : 'in_progress',
            'updated_at' => now(),
        ]);

        $identity = [
            'procurement_plan_id' => $plan->id,
            'kind' => 'commitment',
            'reference_type' => 'purchase_order',
            'reference_id' => $purchaseOrder->id,
        ];
        $transaction = DB::table('procurement_budget_transactions')->where($identity);
        $existing = $transaction->first();
        $payload = [
            'company_id' => $purchaseOrder->buyer_company_id,
            'budget_cycle_id' => $plan->budget_cycle_id,
            'budget_line_id' => $plan->budget_line_id,
            'amount' => $committedAmount,
            'currency' => $purchaseOrder->currency ?: $plan->currency,
            'reference_number' => $purchaseOrder->po_number,
            'occurred_at' => $occurredAt ?: ($purchaseOrder->order_date ?: now())->toDateString(),
            'note' => $note ?? $existing?->note,
            'created_by' => $createdBy ?: $purchaseOrder->created_by,
            'updated_at' => now(),
        ];

        if ($existing) {
            $transaction->update($payload);

            return;
        }

        DB::table('procurement_budget_transactions')->insert(array_merge($identity, $payload, [
            'id' => (string) Str::uuid(),
            'created_at' => now(),
        ]));
    }

    private function ensureWithinAllocation(object $plan, float $amount): void
    {
        if (! $plan->budget_line_id) {
            return;
        }

        $allocated = (float) DB::table('procurement_budget_lines')
            ->where('id', $plan->budget_line_id)
            ->value('allocated_amount');
        $otherCommitments = (float) DB::table('procurement_budget_transactions')
            ->where('budget_line_id', $plan->budget_line_id)
            ->where('kind', 'commitment')
            ->where('procurement_plan_id', '<>', $plan->id)
            ->sum('amount');

        if ($otherCommitments + $amount > $allocated) {
            throw ValidationException::withMessages([
                'amount' => ['Nilai PO melampaui sisa alokasi budget line.'],
            ]);
        }
    }
}
