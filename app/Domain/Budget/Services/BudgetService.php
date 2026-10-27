<?php

namespace App\Domain\Budget\Services;

use App\Domain\Budget\Support\BudgetContext;
use App\Domain\Company\Services\CompanyAccessService;
use App\Domain\Order\Models\PurchaseOrder;
use App\Domain\Rfq\Models\Rfq;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BudgetService
{
    public function __construct(
        private BudgetContext $context,
        private CompanyAccessService $access,
        private BudgetCommitmentService $budgetCommitments,
    ) {}

    public function dashboard(Request $request)
    {
        $company = $this->context->tenant($request);
        $year = (int) $request->query('fiscal_year', now()->year);
        $cycles = DB::table('procurement_budget_cycles')->where('company_id', $company->id)->orderByDesc('fiscal_year')->orderByDesc('starts_at')->get();
        $cycle = $request->query('budget_cycle_id')
            ? $cycles->firstWhere('id', $request->query('budget_cycle_id'))
            : $cycles->first(fn ($item) => (int) $item->fiscal_year === $year) ?? $cycles->first();
        $lines = $cycle ? $this->linesForCycle($company->id, $cycle->id) : collect();
        $plans = $cycle ? $this->plansQuery($company->id, $cycle->id)->latest('updated_at')->limit(12)->get() : collect();
        $allocated = (float) $lines->sum('allocated_amount');
        $committed = (float) $lines->sum('committed_amount');
        $actual = (float) $lines->sum('actual_amount');
        $rfqs = DB::table('rfqs')->where('company_id', $company->id)->latest('created_at')->limit(50)
            ->get(['id', 'title', 'status', 'department', 'created_at']);
        $purchaseOrders = DB::table('purchase_orders')->where('buyer_company_id', $company->id)->latest('created_at')->limit(50)
            ->get(['id', 'po_number', 'status', 'department', 'total_amount', 'currency', 'created_at']);

        return response()->json(['cycle' => $cycle, 'cycles' => $cycles, 'lines' => $lines, 'plans' => $plans, 'rfqs' => $rfqs, 'purchase_orders' => $purchaseOrders, 'summary' => [
            'allocated_amount' => $allocated, 'planned_amount' => (float) $plans->sum('planned_amount'), 'committed_amount' => $committed,
            'actual_amount' => $actual, 'available_amount' => max(0, $allocated - $committed), 'variance_amount' => $allocated - $actual,
            'utilization_percent' => $allocated > 0 ? round(($committed / $allocated) * 100, 1) : 0,
        ]]);
    }

    public function cycles(Request $request)
    {
        $company = $this->context->tenant($request);

        return response()->json(['cycles' => DB::table('procurement_budget_cycles')->where('company_id', $company->id)->orderByDesc('fiscal_year')->orderByDesc('starts_at')->get()]);
    }

    public function storeCycle(Request $request, array $data)
    {
        $company = $this->context->tenant($request);
        $this->manage($request, $company);
        $id = (string) Str::uuid();
        DB::table('procurement_budget_cycles')->insert(array_merge($this->timestamps(), [
            'id' => $id, 'company_id' => $company->id, 'name' => $data['name'], 'fiscal_year' => $data['fiscal_year'], 'currency' => strtoupper($data['currency'] ?? 'IDR'),
            'starts_at' => $data['starts_at'], 'ends_at' => $data['ends_at'], 'total_amount' => $data['total_amount'], 'status' => 'draft', 'notes' => $data['notes'] ?? null, 'created_by' => $request->user()->id,
        ]));

        return response()->json(['cycle' => DB::table('procurement_budget_cycles')->find($id)], 201);
    }

    public function updateCycle(Request $request, string $cycleId, array $data)
    {
        $company = $this->context->tenant($request);
        $this->manage($request, $company);
        $cycle = $this->cycle($company->id, $cycleId);
        if ($cycle->status === 'closed') {
            throw ValidationException::withMessages(['cycle' => ['Periode yang ditutup tidak dapat diubah.']]);
        }
        $fields = array_intersect_key($data, array_flip(['name', 'fiscal_year', 'currency', 'starts_at', 'ends_at', 'total_amount', 'notes']));
        if (isset($fields['currency'])) {
            $fields['currency'] = strtoupper($fields['currency']);
        }
        DB::table('procurement_budget_cycles')->where('id', $cycle->id)->update(array_merge($fields, ['updated_at' => now()]));

        return response()->json(['cycle' => DB::table('procurement_budget_cycles')->find($cycle->id)]);
    }

    public function activateCycle(Request $request, string $cycleId)
    {
        $company = $this->context->tenant($request);
        $this->manage($request, $company);
        $cycle = $this->cycle($company->id, $cycleId);
        DB::table('procurement_budget_cycles')->where('id', $cycle->id)->update(['status' => 'active', 'approved_by' => $request->user()->id, 'approved_at' => now(), 'updated_at' => now()]);

        return response()->json(['message' => 'Periode anggaran diaktifkan.', 'cycle' => DB::table('procurement_budget_cycles')->find($cycle->id)]);
    }

    public function lines(Request $request)
    {
        $company = $this->context->tenant($request);
        $cycle = $this->cycle($company->id, $request->query('budget_cycle_id'));

        return response()->json(['lines' => $this->linesForCycle($company->id, $cycle->id)]);
    }

    public function storeLine(Request $request, array $data)
    {
        $company = $this->context->tenant($request);
        $this->manage($request, $company);
        $cycle = $this->cycle($company->id, $data['budget_cycle_id']);
        if ($cycle->status === 'closed') {
            throw ValidationException::withMessages(['budget_cycle_id' => ['Periode anggaran telah ditutup.']]);
        }
        $this->assertAllocationWithinCycle($company->id, $cycle, (float) $data['allocated_amount']);
        $id = (string) Str::uuid();
        DB::table('procurement_budget_lines')->insert(array_merge($this->timestamps(), [
            'id' => $id, 'company_id' => $company->id, 'budget_cycle_id' => $cycle->id, 'code' => strtoupper($data['code']), 'name' => $data['name'], 'department' => $data['department'] ?? null,
            'category' => $data['category'] ?? null, 'allocated_amount' => $data['allocated_amount'], 'currency' => strtoupper($data['currency'] ?? $cycle->currency), 'owner_id' => $data['owner_id'] ?? null, 'status' => 'active', 'notes' => $data['notes'] ?? null,
        ]));

        return response()->json(['line' => $this->lineWithFigures($company->id, $id)], 201);
    }

    public function updateLine(Request $request, string $lineId, array $data)
    {
        $company = $this->context->tenant($request);
        $this->manage($request, $company);
        $line = $this->line($company->id, $lineId);
        $cycle = $this->cycle($company->id, $line->budget_cycle_id);
        if ($cycle->status === 'closed') {
            throw ValidationException::withMessages(['line' => ['Periode anggaran telah ditutup.']]);
        }
        $fields = array_intersect_key($data, array_flip(['code', 'name', 'department', 'category', 'allocated_amount', 'currency', 'owner_id', 'notes', 'status']));
        if (array_key_exists('allocated_amount', $fields)) {
            $this->assertAllocationWithinCycle($company->id, $cycle, (float) $fields['allocated_amount'], $line->id);
        }
        if (isset($fields['code'])) {
            $fields['code'] = strtoupper($fields['code']);
        } if (isset($fields['currency'])) {
            $fields['currency'] = strtoupper($fields['currency']);
        }
        DB::table('procurement_budget_lines')->where('id', $line->id)->update(array_merge($fields, ['updated_at' => now()]));

        return response()->json(['line' => $this->lineWithFigures($company->id, $line->id)]);
    }

    public function plans(Request $request)
    {
        $company = $this->context->tenant($request);
        $query = $this->plansQuery($company->id, $request->query('budget_cycle_id'));
        foreach (['status', 'department', 'category'] as $filter) {
            if ($request->filled($filter)) {
                $query->where("procurement_plans.$filter", $request->query($filter));
            }
        }

        return response()->json(['plans' => $query->latest('updated_at')->paginate(min(100, max(10, (int) $request->query('per_page', 30))))]);
    }

    public function storePlan(Request $request, array $data)
    {
        $company = $this->context->tenant($request);
        $this->manage($request, $company);
        $cycle = $this->cycle($company->id, $data['budget_cycle_id']);
        if ($cycle->status !== 'active') {
            throw ValidationException::withMessages(['budget_cycle_id' => ['Aktifkan periode anggaran sebelum membuat rencana pengadaan.']]);
        }
        $line = $this->optionalLine($company->id, $data['budget_line_id'] ?? null, $cycle->id);
        $id = (string) Str::uuid();
        DB::table('procurement_plans')->insert(array_merge($this->timestamps(), ['id' => $id, 'company_id' => $company->id, 'budget_cycle_id' => $cycle->id, 'budget_line_id' => $line?->id, 'plan_number' => $this->planNumber(), 'title' => $data['title'], 'description' => $data['description'] ?? null, 'department' => $data['department'] ?? $line?->department, 'category' => $data['category'] ?? $line?->category, 'priority' => $data['priority'] ?? 'normal', 'currency' => strtoupper($data['currency'] ?? $cycle->currency), 'planned_amount' => $data['planned_amount'], 'planned_start_at' => $data['planned_start_at'] ?? null, 'planned_end_at' => $data['planned_end_at'] ?? null, 'status' => 'draft', 'created_by' => $request->user()->id]));

        return response()->json(['plan' => $this->planWithFigures($company->id, $id)], 201);
    }

    public function updatePlan(Request $request, string $planId, array $data)
    {
        $company = $this->context->tenant($request);
        $this->manage($request, $company);
        $plan = $this->plan($company->id, $planId);
        if (in_array($plan->status, ['approved', 'in_progress', 'completed'], true)) {
            throw ValidationException::withMessages(['plan' => ['Rencana yang sudah disetujui tidak dapat diubah.']]);
        }
        $fields = array_intersect_key($data, array_flip(['title', 'description', 'department', 'category', 'priority', 'currency', 'planned_amount', 'planned_start_at', 'planned_end_at']));
        if (isset($fields['currency'])) {
            $fields['currency'] = strtoupper($fields['currency']);
        }
        if (array_key_exists('budget_line_id', $data)) {
            $fields['budget_line_id'] = $this->optionalLine($company->id, $data['budget_line_id'], $plan->budget_cycle_id)?->id;
        }
        DB::table('procurement_plans')->where('id', $plan->id)->update(array_merge($fields, ['updated_at' => now()]));

        return response()->json(['plan' => $this->planWithFigures($company->id, $plan->id)]);
    }

    public function submitPlan(Request $request, string $planId)
    {
        $company = $this->context->tenant($request);
        $this->manage($request, $company);
        $plan = $this->plan($company->id, $planId);
        $this->assertPlanWithinBudget($company->id, $plan);
        if (! in_array($plan->status, ['draft', 'rejected'], true)) {
            throw ValidationException::withMessages(['plan' => ['Hanya draft atau rencana revisi yang dapat diajukan.']]);
        }
        DB::transaction(function () use ($company, $plan, $request) {
            DB::table('procurement_plans')->where('id', $plan->id)->update(['status' => 'submitted', 'updated_at' => now()]);
            DB::table('procurement_plan_approvals')->insert(array_merge($this->timestamps(), ['id' => (string) Str::uuid(), 'company_id' => $company->id, 'procurement_plan_id' => $plan->id, 'requested_by' => $request->user()->id, 'status' => 'pending']));
        });

        return response()->json(['message' => 'Rencana pengadaan diajukan untuk persetujuan.', 'plan' => $this->planWithFigures($company->id, $plan->id)]);
    }

    public function approvePlan(Request $request, string $planId)
    {
        $company = $this->context->tenant($request);
        $this->approve($request, $company);
        $plan = $this->plan($company->id, $planId);
        if ($plan->status !== 'submitted') {
            throw ValidationException::withMessages(['plan' => ['Rencana belum menunggu persetujuan.']]);
        } $this->assertPlanWithinBudget($company->id, $plan);
        DB::transaction(function () use ($plan, $request) {
            DB::table('procurement_plans')->where('id', $plan->id)->update(['status' => 'approved', 'approved_by' => $request->user()->id, 'approved_at' => now(), 'updated_at' => now()]);
            DB::table('procurement_plan_approvals')->where('procurement_plan_id', $plan->id)->where('status', 'pending')->latest('created_at')->limit(1)->update(['status' => 'approved', 'decided_by' => $request->user()->id, 'decided_at' => now(), 'note' => $request->input('note'), 'updated_at' => now()]);
        });

        return response()->json(['message' => 'Rencana pengadaan disetujui.', 'plan' => $this->planWithFigures($company->id, $plan->id)]);
    }

    public function rejectPlan(Request $request, string $planId)
    {
        $company = $this->context->tenant($request);
        $this->approve($request, $company);
        $plan = $this->plan($company->id, $planId);
        if ($plan->status !== 'submitted') {
            throw ValidationException::withMessages(['plan' => ['Rencana belum menunggu persetujuan.']]);
        }
        DB::transaction(function () use ($plan, $request) {
            DB::table('procurement_plans')->where('id', $plan->id)->update(['status' => 'rejected', 'updated_at' => now()]);
            DB::table('procurement_plan_approvals')->where('procurement_plan_id', $plan->id)->where('status', 'pending')->latest('created_at')->limit(1)->update(['status' => 'rejected', 'decided_by' => $request->user()->id, 'decided_at' => now(), 'note' => $request->input('note'), 'updated_at' => now()]);
        });

        return response()->json(['message' => 'Rencana dikembalikan untuk revisi.', 'plan' => $this->planWithFigures($company->id, $plan->id)]);
    }

    public function linkRfq(Request $request, string $planId, array $data)
    {
        $company = $this->context->tenant($request);
        $this->manage($request, $company);
        $plan = $this->plan($company->id, $planId);
        $rfq = Rfq::query()->where('company_id', $company->id)->findOrFail($data['rfq_id']);
        DB::transaction(function () use ($plan, $rfq) {
            DB::table('procurement_plans')->where('id', $plan->id)->update(['rfq_id' => $rfq->id, 'status' => in_array($plan->status, ['approved', 'submitted'], true) ? 'in_progress' : $plan->status, 'updated_at' => now()]);
            DB::table('rfqs')->where('id', $rfq->id)->update(['procurement_plan_id' => $plan->id, 'updated_at' => now()]);
        });

        return response()->json(['message' => 'PR terhubung ke rencana pengadaan.', 'plan' => $this->planWithFigures($company->id, $plan->id)]);
    }

    public function linkPurchaseOrder(Request $request, string $planId, array $data)
    {
        $company = $this->context->tenant($request);
        $this->manage($request, $company);
        $plan = $this->plan($company->id, $planId);
        $po = PurchaseOrder::query()->where('buyer_company_id', $company->id)->findOrFail($data['purchase_order_id']);
        $amount = (float) ($data['amount'] ?? $po->total_amount);
        DB::transaction(function () use ($plan, $po, $amount, $data, $request) {
            $this->budgetCommitments->sync(
                $po,
                $amount,
                $plan->id,
                $data['note'] ?? null,
                $data['occurred_at'] ?? null,
                $request->user()->id,
            );
        });

        return response()->json(['message' => 'PO terhubung dan komitmen anggaran dicatat.', 'plan' => $this->planWithFigures($company->id, $plan->id)]);
    }

    public function recordActual(Request $request, string $planId, array $data)
    {
        $company = $this->context->tenant($request);
        $this->manage($request, $company);
        $plan = $this->plan($company->id, $planId);
        $amount = (float) ($data['amount'] ?? 0);
        if ($amount <= 0 && $plan->purchase_order_id) {
            $amount = (float) PurchaseOrder::query()->where('buyer_company_id', $company->id)->whereKey($plan->purchase_order_id)->value('total_amount');
        } if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => ['Masukkan nilai realisasi.']]);
        }
        $refId = $plan->purchase_order_id ?: $plan->id;
        $refType = $plan->purchase_order_id ? 'purchase_order' : 'manual_actual';
        $number = $plan->purchase_order_id ? PurchaseOrder::find($plan->purchase_order_id)?->po_number : $plan->plan_number;
        $this->upsertTransaction($company->id, $plan, 'actual', $amount, $refType, $refId, $number, $data['note'] ?? null, $request->user()->id, $data['occurred_at'] ?? now()->toDateString());
        DB::table('procurement_plans')->where('id', $plan->id)->update(['status' => 'completed', 'updated_at' => now()]);

        return response()->json(['message' => 'Realisasi anggaran dicatat.', 'plan' => $this->planWithFigures($company->id, $plan->id)]);
    }

    private function plansQuery(string $companyId, ?string $cycleId = null)
    {
        $query = DB::table('procurement_plans')->leftJoin('procurement_budget_lines as lines', 'lines.id', '=', 'procurement_plans.budget_line_id')->select('procurement_plans.*', 'lines.code as budget_line_code', 'lines.name as budget_line_name', 'lines.allocated_amount as budget_line_amount')->where('procurement_plans.company_id', $companyId);
        if ($cycleId) {
            $query->where('procurement_plans.budget_cycle_id', $cycleId);
        }

        return $query;
    }

    private function linesForCycle(string $companyId, string $cycleId)
    {
        $figures = DB::table('procurement_budget_transactions')
            ->select(
                'budget_line_id',
                DB::raw("SUM(CASE WHEN kind = 'commitment' THEN amount ELSE 0 END) committed_amount"),
                DB::raw("SUM(CASE WHEN kind = 'actual' THEN amount ELSE 0 END) actual_amount"),
                DB::raw("COUNT(DISTINCT CASE WHEN kind = 'commitment' AND reference_type = 'purchase_order' THEN reference_id END) commitment_count"),
            )
            ->where('company_id', $companyId)
            ->where('budget_cycle_id', $cycleId)
            ->groupBy('budget_line_id');

        return DB::table('procurement_budget_lines as lines')->leftJoinSub($figures, 'figures', 'figures.budget_line_id', '=', 'lines.id')->where('lines.company_id', $companyId)->where('lines.budget_cycle_id', $cycleId)->select('lines.*', DB::raw('COALESCE(figures.committed_amount, 0) as committed_amount'), DB::raw('COALESCE(figures.actual_amount, 0) as actual_amount'), DB::raw('COALESCE(figures.commitment_count, 0) as commitment_count'), DB::raw('lines.allocated_amount - COALESCE(figures.committed_amount, 0) as available_amount'))->orderBy('lines.code')->get();
    }

    private function planWithFigures(string $companyId, string $planId)
    {
        $plan = $this->plansQuery($companyId)->where('procurement_plans.id', $planId)->firstOrFail();
        $plan->transactions = DB::table('procurement_budget_transactions')->where('company_id', $companyId)->where('procurement_plan_id', $planId)->latest('occurred_at')->get();
        $plan->approvals = DB::table('procurement_plan_approvals')->where('company_id', $companyId)->where('procurement_plan_id', $planId)->latest()->get();

        return $plan;
    }

    private function lineWithFigures(string $companyId, string $lineId)
    {
        return $this->linesForCycle($companyId, $this->line($companyId, $lineId)->budget_cycle_id)->firstWhere('id', $lineId);
    }

    private function cycle(string $companyId, ?string $id)
    {
        return DB::table('procurement_budget_cycles')->where('company_id', $companyId)->where('id', $id)->firstOrFail();
    }

    private function line(string $companyId, string $id)
    {
        return DB::table('procurement_budget_lines')->where('company_id', $companyId)->where('id', $id)->firstOrFail();
    }

    private function optionalLine(string $companyId, ?string $id, string $cycleId)
    {
        if (! $id) {
            return null;
        }

        return DB::table('procurement_budget_lines')->where('company_id', $companyId)->where('budget_cycle_id', $cycleId)->where('id', $id)->firstOrFail();
    }

    private function plan(string $companyId, string $id)
    {
        return DB::table('procurement_plans')->where('company_id', $companyId)->where('id', $id)->firstOrFail();
    }

    private function planNumber(): string
    {
        return 'PPLAN-'.now()->format('Ymd').'-'.strtoupper(Str::random(6));
    }

    private function timestamps(): array
    {
        return ['created_at' => now(), 'updated_at' => now()];
    }

    private function manage(Request $request, $company): void
    {
        $this->access->assertCan($request->user(), $company, 'budget.manage', 'Anda tidak memiliki izin untuk mengelola anggaran.');
    }

    private function approve(Request $request, $company): void
    {
        $this->access->assertCan($request->user(), $company, 'budget.approve', 'Anda tidak memiliki izin untuk menyetujui rencana anggaran.');
    }

    private function assertAllocationWithinCycle(string $companyId, object $cycle, float $amount, ?string $exceptLineId = null): void
    {
        $query = DB::table('procurement_budget_lines')->where('company_id', $companyId)->where('budget_cycle_id', $cycle->id);
        if ($exceptLineId) {
            $query->where('id', '<>', $exceptLineId);
        }
        $allocated = (float) $query->sum('allocated_amount');
        if ($allocated + $amount > (float) $cycle->total_amount) {
            throw ValidationException::withMessages(['allocated_amount' => ['Total alokasi tidak boleh melampaui total periode anggaran.']]);
        }
    }

    private function assertPlanWithinBudget(string $companyId, object $plan): void
    {
        if (! $plan->budget_line_id) {
            return;
        }$line = $this->lineWithFigures($companyId, $plan->budget_line_id);
        $other = (float) DB::table('procurement_plans')->where('company_id', $companyId)->where('budget_line_id', $line->id)->where('id', '<>', $plan->id)->whereIn('status', ['submitted', 'approved', 'in_progress', 'completed'])->sum('planned_amount');
        if ($other + (float) $plan->planned_amount > (float) $line->allocated_amount) {
            throw ValidationException::withMessages(['planned_amount' => ['Nilai rencana melampaui alokasi budget line.']]);
        }
    }

    private function upsertTransaction(string $companyId, object $plan, string $kind, float $amount, string $referenceType, string $referenceId, ?string $number, ?string $note, ?string $userId, ?string $occurredAt): void
    {
        $identity = ['procurement_plan_id' => $plan->id, 'kind' => $kind, 'reference_type' => $referenceType, 'reference_id' => $referenceId];
        $payload = ['company_id' => $companyId, 'budget_cycle_id' => $plan->budget_cycle_id, 'budget_line_id' => $plan->budget_line_id, 'amount' => $amount, 'currency' => $plan->currency, 'reference_number' => $number, 'occurred_at' => $occurredAt, 'note' => $note, 'created_by' => $userId, 'updated_at' => now()];
        $existing = DB::table('procurement_budget_transactions')->where($identity);
        if ($existing->exists()) {
            $existing->update($payload);

            return;
        }
        DB::table('procurement_budget_transactions')->insert(array_merge($identity, $payload, ['id' => (string) Str::uuid(), 'created_at' => now()]));
    }
}
