<?php

namespace App\Domain\Crm\Services;

use App\Domain\Company\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Executes safe, auditable CRM workflow actions after a CRM domain event. */
class CrmWorkflowRunner
{
    public function run(Company $company, string $trigger, array $context = []): void
    {
        $workflows = DB::table('crm_workflows')->where('company_id', $company->id)->where('user_id', $context['user_id'] ?? null)
            ->where('trigger', $trigger)->where('is_active', true)->get();

        foreach ($workflows as $workflow) {
            $conditions = json_decode($workflow->conditions ?? '[]', true) ?: [];
            if (! $this->matches($conditions, $context)) continue;
            foreach (json_decode($workflow->actions ?? '[]', true) ?: [] as $action) {
                if (($action['type'] ?? null) !== 'create_task') continue;
                DB::table('crm_activities')->insert([
                    'id' => (string) Str::uuid(), 'company_id' => $company->id, 'user_id' => $context['user_id'] ?? null,
                    'customer_id' => $context['customer_id'] ?? null, 'lead_id' => $context['lead_id'] ?? null,
                    'deal_id' => $context['deal_id'] ?? null, 'assignee_id' => $action['assignee_id'] ?? null,
                    'created_by' => $context['user_id'] ?? null, 'type' => 'task',
                    'title' => $action['title'] ?? 'Tindak lanjut workflow: ' . $workflow->name,
                    'description' => $action['description'] ?? 'Dibuat otomatis oleh workflow CRM.',
                    'status' => 'open', 'due_at' => now()->addDays((int) ($action['due_in_days'] ?? 1)),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    private function matches(array $conditions, array $context): bool
    {
        foreach ($conditions as $field => $expected) {
            if (($context[$field] ?? null) != $expected) return false;
        }

        return true;
    }
}
