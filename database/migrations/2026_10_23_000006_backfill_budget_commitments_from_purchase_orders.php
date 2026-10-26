<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('procurement_plans')
            ->where(function ($query): void {
                $query->whereNotNull('rfq_id')->orWhereNotNull('purchase_order_id');
            })
            ->orderBy('created_at')
            ->each(function (object $plan): void {
                $purchaseOrder = $plan->purchase_order_id
                    ? DB::table('purchase_orders')->where('id', $plan->purchase_order_id)->first()
                    : DB::table('purchase_orders')
                        ->where('rfq_id', $plan->rfq_id)
                        ->where('buyer_company_id', $plan->company_id)
                        ->oldest('created_at')
                        ->first();

                if (! $purchaseOrder) {
                    return;
                }

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
                $payload = [
                    'company_id' => $plan->company_id,
                    'budget_cycle_id' => $plan->budget_cycle_id,
                    'budget_line_id' => $plan->budget_line_id,
                    'amount' => $purchaseOrder->total_amount ?? 0,
                    'currency' => $purchaseOrder->currency ?: $plan->currency,
                    'reference_number' => $purchaseOrder->po_number,
                    'occurred_at' => $purchaseOrder->order_date ?: now()->toDateString(),
                    'created_by' => $purchaseOrder->created_by,
                    'updated_at' => now(),
                ];

                if ($transaction->exists()) {
                    $transaction->update($payload);

                    return;
                }

                DB::table('procurement_budget_transactions')->insert(array_merge($identity, $payload, [
                    'id' => (string) Str::uuid(),
                    'created_at' => now(),
                ]));
            });
    }

    public function down(): void
    {
        // Historical commitments are financial audit data and must not be deleted on rollback.
    }
};
