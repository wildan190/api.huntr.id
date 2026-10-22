<?php
namespace App\Domain\Crm\Console;
use App\Domain\Crm\Services\CrmCampaignDispatchService;
use Illuminate\Console\Command;
class DispatchCrmCampaignsCommand extends Command { protected $signature='crm:dispatch-campaigns'; protected $description='Queue scheduled CRM email campaigns'; public function handle(CrmCampaignDispatchService $service):int{$this->info('Queued '.$service->dispatchDue().' CRM campaign recipients.');return self::SUCCESS;} }
