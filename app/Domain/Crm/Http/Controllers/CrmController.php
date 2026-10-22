<?php

namespace App\Domain\Crm\Http\Controllers;

use App\Domain\Crm\Services\CrmAppService;
use App\Domain\Crm\Services\CrmService;
use App\Domain\Crm\Services\CrmEnterpriseService;
use App\Domain\Crm\Services\CrmOAuthService;
use App\Domain\Crm\Services\CrmWhatsAppService;
use App\Domain\Crm\Services\CrmEmailInboxSyncService;
use Illuminate\Http\Request;

class CrmController
{
    public function apps(Request $request, CrmAppService $service) { return $service->apps($request); }
    public function install(Request $request, CrmAppService $service) { return $service->install($request); }
    public function uninstall(Request $request, CrmAppService $service) { return $service->uninstall($request); }
    public function dashboard(Request $request, CrmService $service) { return $service->dashboard($request); }
    public function customers(Request $request, CrmService $service) { return $service->customers($request); }
    public function customer(Request $request, string $customerId, CrmService $service) { return $service->customer($request, $customerId); }
    public function storeCustomer(Request $request, CrmService $service) { return $service->storeCustomer($request); }
    public function updateCustomer(Request $request, string $customerId, CrmService $service) { return $service->updateCustomer($request, $customerId); }
    public function leads(Request $request, CrmService $service) { return $service->leads($request); }
    public function storeLead(Request $request, CrmService $service) { return $service->storeLead($request); }
    public function updateLead(Request $request, string $leadId, CrmService $service) { return $service->updateLead($request, $leadId); }
    public function convertLead(Request $request, string $leadId, CrmService $service) { return $service->convertLead($request, $leadId); }
    public function deals(Request $request, CrmService $service) { return $service->deals($request); }
    public function storeDeal(Request $request, CrmService $service) { return $service->storeDeal($request); }
    public function updateDeal(Request $request, string $dealId, CrmService $service) { return $service->updateDeal($request, $dealId); }
    public function activities(Request $request, CrmService $service) { return $service->activities($request); }
    public function storeActivity(Request $request, CrmService $service) { return $service->storeActivity($request); }
    public function updateActivity(Request $request, string $activityId, CrmService $service) { return $service->updateActivity($request, $activityId); }
    public function tickets(Request $request, CrmService $service) { return $service->tickets($request); }
    public function storeTicket(Request $request, CrmService $service) { return $service->storeTicket($request); }
    public function updateTicket(Request $request, string $ticketId, CrmService $service) { return $service->updateTicket($request, $ticketId); }
    public function ticketMessages(Request $request, string $ticketId, CrmService $service) { return $service->ticketMessages($request, $ticketId); }
    public function storeTicketMessage(Request $request, string $ticketId, CrmService $service) { return $service->storeTicketMessage($request, $ticketId); }
    public function conversations(Request $request, CrmService $service) { return $service->conversations($request); }
    public function storeConversation(Request $request, CrmService $service) { return $service->storeConversation($request); }
    public function conversationMessages(Request $request, string $conversationId, CrmService $service) { return $service->conversationMessages($request, $conversationId); }
    public function storeConversationMessage(Request $request, string $conversationId, CrmService $service) { return $service->storeConversationMessage($request, $conversationId); }
    public function quotes(Request $request, CrmService $service) { return $service->quotes($request); }
    public function storeQuote(Request $request, CrmService $service) { return $service->storeQuote($request); }
    public function territories(Request $request, CrmService $service) { return $service->territories($request); }
    public function storeTerritory(Request $request, CrmService $service) { return $service->storeTerritory($request); }
    public function workflows(Request $request, CrmService $service) { return $service->workflows($request); }
    public function storeWorkflow(Request $request, CrmService $service) { return $service->storeWorkflow($request); }
    public function emailConnections(Request $request, CrmService $service) { return $service->emailConnections($request); }
    public function storeEmailConnection(Request $request, CrmService $service) { return $service->storeEmailConnection($request); }
    public function authorizeEmail(Request $request, string $provider, CrmOAuthService $service) { return $service->authorize($request, $provider); }
    public function emailOAuthCallback(Request $request, string $provider, CrmOAuthService $service) { return $service->callback($request, $provider); }
    public function syncEmailInbox(Request $request, CrmEmailInboxSyncService $service, \App\Domain\Crm\Support\CrmContext $context) { return $service->syncRequest($request, $context); }
    public function startWhatsApp(Request $request, CrmWhatsAppService $service) { return $service->start($request); }
    public function whatsAppStatus(Request $request, CrmWhatsAppService $service) { return $service->status($request); }
    public function sendWhatsApp(Request $request, CrmWhatsAppService $service) { return $service->send($request); }
    public function disconnectWhatsApp(Request $request, CrmWhatsAppService $service) { return $service->disconnect($request); }
    public function whatsAppEvent(Request $request, CrmWhatsAppService $service) { return $service->webhook($request); }
    public function storeWhatsAppSessionBackup(Request $request, CrmWhatsAppService $service) { return $service->storeSessionBackup($request); }
    public function whatsAppSessionBackup(Request $request, string $tenantId, CrmWhatsAppService $service) { return $service->sessionBackup($request, $tenantId); }
    public function forecast(Request $request, CrmService $service) { return $service->forecast($request); }
    public function contacts(Request $request, CrmEnterpriseService $service) { return $service->contacts($request); }
    public function storeContact(Request $request, CrmEnterpriseService $service) { return $service->storeContact($request); }
    public function campaigns(Request $request, CrmEnterpriseService $service) { return $service->campaigns($request); }
    public function storeCampaign(Request $request, CrmEnterpriseService $service) { return $service->storeCampaign($request); }
    public function knowledge(Request $request, CrmEnterpriseService $service) { return $service->knowledge($request); }
    public function storeKnowledge(Request $request, CrmEnterpriseService $service) { return $service->storeKnowledge($request); }
    public function integrations(Request $request, CrmEnterpriseService $service) { return $service->integrations($request); }
    public function connectIntegration(Request $request, CrmEnterpriseService $service) { return $service->connectIntegration($request); }
    public function rates(Request $request, CrmEnterpriseService $service) { return $service->rates($request); }
    public function storeRate(Request $request, CrmEnterpriseService $service) { return $service->storeRate($request); }
    public function targets(Request $request, CrmEnterpriseService $service) { return $service->targets($request); }
    public function storeTarget(Request $request, CrmEnterpriseService $service) { return $service->storeTarget($request); }
    public function requestQuoteApproval(Request $request, string $quoteId, CrmEnterpriseService $service) { return $service->requestQuoteApproval($request, $quoteId); }
    public function decideQuoteApproval(Request $request, string $quoteId, string $approvalId, CrmEnterpriseService $service) { return $service->decideQuoteApproval($request, $quoteId, $approvalId); }
    public function reports(Request $request, CrmEnterpriseService $service) { return $service->reports($request); }
}
