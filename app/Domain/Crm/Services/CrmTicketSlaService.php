<?php

namespace App\Domain\Crm\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CrmTicketSlaService
{
    public function escalateBreaches(): int
    {
        $tickets = DB::table('crm_tickets')->whereIn('status', ['open', 'pending'])
            ->whereNull('sla_escalated_at')
            ->where(fn ($query) => $query->where('first_response_due_at', '<', now())->orWhere('resolve_due_at', '<', now()))
            ->get();
        foreach ($tickets as $ticket) {
            DB::transaction(function () use ($ticket) {
                if (DB::table('crm_tickets')->where('id', $ticket->id)->whereNull('sla_escalated_at')->update(['sla_escalated_at' => now(), 'updated_at' => now()])) {
                    DB::table('crm_activities')->insert([
                        'id' => (string) Str::uuid(), 'company_id' => $ticket->company_id, 'user_id' => $ticket->user_id, 'customer_id' => $ticket->customer_id,
                        'created_by' => $ticket->assignee_id, 'assignee_id' => $ticket->assignee_id, 'type' => 'task',
                        'title' => "Eskalasi SLA {$ticket->number}", 'description' => "Tiket {$ticket->subject} melewati target respons atau resolusi.",
                        'status' => 'open', 'due_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            });
        }
        return $tickets->count();
    }
}
