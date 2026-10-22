<?php

namespace App\Domain\Crm\Console;

use App\Domain\Crm\Services\CrmTicketSlaService;
use Illuminate\Console\Command;

class EscalateCrmTicketSlaCommand extends Command
{
    protected $signature = 'crm:escalate-ticket-sla';
    protected $description = 'Create CRM escalation tasks for breached support ticket SLAs';
    public function handle(CrmTicketSlaService $service): int
    {
        $this->info("Escalated {$service->escalateBreaches()} CRM ticket(s).");
        return self::SUCCESS;
    }
}
